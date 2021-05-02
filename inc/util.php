<?php

/**
 * The util class.
 * This contains shared utilities for resolving Meta Box fields
 *
 * @package  WPGraphQL_MetaBox
 */

use GraphQLRelay\Relay;
use WPGraphQL\AppContext;
use WPGraphQL\Data\DataSource;
use WPGraphQL\Model\User;

/**
 * The WPGraphQL_MetaBox_Util class.
 */
final class WPGraphQL_MetaBox_Util
{

  /**
   * Resolves metabox union type for post-to-post with multiple type fields
   *
   * @param   mixed         The metabox field configuration
   * @return  string|mixed  The GraphQL type
   * @since   0.2.1
   * @access  public
   */
  public static function resolve_graphql_union_type($field)
  {
    [
      'multiple' => $multiple,
      'clone' => $clone,
      'post_type' => $post_type,
      'graphql_name' => $graphql_name,
    ] = $field;

    // get all GraphQL type names for types in the union
    $union_names = array_reduce($post_type, function ($a, $c) {
      $post_type_object = get_post_type_object($c);
      if ($post_type_object->show_in_graphql === true) {
        array_push($a, ucfirst($post_type_object->graphql_single_name));
      }
      return $a;
    }, []);

    // build a unique union GraphQL type
    $union_name = ucfirst($graphql_name) . 'To' . join('And', $union_names) . 'Union';
    register_graphql_union_type($union_name, [
      'typeNames'       => $union_names,
      'resolveType' => function ($union) {
        return DataSource::resolve_node_type($union);
      }
    ]);

    if ($multiple) {
      $union_name = ['list_of' => $union_name];
    }
    if ($clone) {
      $union_name = ['list_of' => $union_name];
    }
    return $union_name;
  }

  /**
   * Returns the appropriate resolver callback for the field
   * The callback is executed by WPGraphQL to resolve a field as part of a request
   * All values are retrieved via `rwmb_meta` and passed through a safety function as
   * GraphQL clients will throw errors for mismatched types.
   *
   * @param   mixed $field      The metabox field configuration
   * @param   array $meta_args  The metabox field arguments
   * @return  mixed The payload response
   * @since   0.3.0
   * @access  public
   */
  public static function get_graphql_field_resolver_callback($field, $meta_args = null)
  {
    [
      'type' => $type,
      'id' => $field_id,
    ] = $field;

    switch ($type) {
      case 'group':
      case 'number':
      case 'range':
        return function ($node) use ($field_id, $meta_args, $type) {
          $field = self::get_rwmb_meta_value($node, $field_id, $meta_args);
          return self::get_resolved_field_value($field, self::get_safe_value_callback($type));
        };
      case 'switch':
      case 'checkbox':
      case 'checkbox_list':
      case 'background':
      case 'color':
      case 'custom_html':
      case 'date':
      case 'heading':
      case 'datetime':
      case 'oembed':
      case 'password':
      case 'radio':
      case 'textarea':
      case 'time':
      case 'select':
      case 'email':
      case 'tel':
      case 'text':
      case 'fieldset_text':
      case 'text_list':
      case 'key_value':
      case 'select_advanced':
      case 'url':
      case 'wysiwyg':
        return function ($node) use ($field_id, $meta_args, $type) {
          $field = self::get_rwmb_meta_value($node, $field_id, $meta_args);
          return self::get_resolved_field_value($field, self::get_safe_value_callback($type));
        };
      case 'single_image':
        return function ($node, $args) use ($field_id, $meta_args, $type) {
          $size = !isset($args['size']) ? 'thumbnail' : $args['size'];
          $merged_args = array_merge($meta_args, ['size' => $size]);
          $field = rwmb_meta($field_id, $merged_args, $node->ID);

          return self::get_resolved_field_value($field, self::get_safe_value_callback($type));
        };
      case 'user':
        return function ($node, $args, AppContext $context) use ($field_id, $meta_args) {
          $field = self::get_rwmb_meta_value($node, $field_id, $meta_args);
          $resolve_field = function ($field_data) use ($context) {
            $user = $context->get_loader('user')->load_deferred($field_data);
            return isset($user) ? $user : null;
          };

          return self::get_resolved_field_value($field, $resolve_field);
        };
      case 'post':
        return function ($node, $args, AppContext $context) use ($field_id, $meta_args) {
          $field = self::get_rwmb_meta_value($node, $field_id, $meta_args);
          $resolve_field = function ($field_data) use ($context) {
            $post = $context->get_loader('post')->load_deferred($field_data);
            return isset($post) ? $post : null;
          };

          return self::get_resolved_field_value($field, $resolve_field);
        };
      case 'taxonomy':
      case 'taxonomy_advanced':
        return function ($node, $args, AppContext $context) use ($field_id, $meta_args) {
          $field = self::get_rwmb_meta_value($node, $field_id, $meta_args);
          $resolve_field = function ($field_data) use ($context) {
            $taxonomy = $context->get_loader('term')->load_deferred($field_data->term_id);
            return isset($taxonomy) ? $taxonomy : null;
          };
          return self::get_resolved_field_value($field, $resolve_field);
        };
      default:
        return function () {
          return null;
        };
    }
  }

  /**
   * Resolves GraphQL input args for a given GraphQL type
   *
   * @param   string      $type The GraphQL type name
   * @return  array|null  The GraphQL arg config
   * @since   0.1.0
   * @access  private
   */
  private static function get_graphql_field_args($type)
  {
    switch ($type) {
      case 'MBSingleImage':
        return [
          'size' => [
            'type'        => 'MediaItemSizeEnum',
            'description' => __('Simple Image size', 'wpgraphql-metabox'),
          ],
        ];
      default:
        return null;
    }
  }

  /**
   * Gets the correct GraphQL type for a Meta Box field. Handles multiple and cloned field types
   *
   * @param   array $field  The Meta Box field config
   * @since   0.0.1
   * @access  private
   * @return  string
   */
  public static function get_graphql_type($field)
  {
    if (!is_array($field)) {
      return null;
    }

    ['multiple' => $multiple, 'clone' => $clone] = $field;
    $resolved_type = self::get_field_graphql_type_name($field);
    if ($multiple) {
      $resolved_type = ['list_of' => $resolved_type];
    }
    if ($clone) {
      $resolved_type = ['list_of' => $resolved_type];
    }
    return $resolved_type;
  }

  /**
   * Gets the appropriate field resolver based on field type
   *
   * @param  string   $type The Metabox field type
   * @return function A callback to ensure valid GraphQL field type
   * @since  0.4.0
   * @access private
   */
  private static function get_safe_value_callback($type)
  {
    switch ($type) {
      case 'group':
        return function ($field_data) {
          return $field_data;
        };
      case 'number':
      case 'range':
        return function ($field_data) {
          return is_numeric($field_data) ? $field_data : null;
        };
      case 'switch':
      case 'checkbox':
      case 'checkbox_list':
      case 'background':
      case 'color':
      case 'custom_html':
      case 'date':
      case 'heading':
      case 'datetime':
      case 'oembed':
      case 'password':
      case 'radio':
      case 'textarea':
      case 'time':
      case 'select':
      case 'email':
      case 'tel':
      case 'text':
      case 'fieldset_text':
      case 'text_list':
      case 'key_value':
      case 'select_advanced':
      case 'url':
      case 'wysiwyg':
      case 'single_image':
        return function ($field_data) {
          return isset($field_data) ? $field_data : null;
        };
      default:
        return function () {
          return null;
        };
    }
  }

  /**
   * Gets the field value from Meta Box
   * Executes `rwmb_meta` for the field on the currently resolving entity
   *
   * @param   \WPGraphQL\Model  $node     The node to resolve
   * @param   string            $field_id The metabox field id
   * @param   array             $args     The metabox field args
   * @return  mixed             The field contents
   * @since   0.2.0
   * @access  private
   */
  private static function get_rwmb_meta_value($node, $field_id, $args = null)
  {
    if ($node instanceof User) {
      return rwmb_meta($field_id, $args, $node->userId);
    }
    return rwmb_meta($field_id, $args, $node->ID);
  }

  /**
   * Maps a Meta Box field type to the GraphQL type
   *
   * @param   array   The metabox field configuration
   * @return  mixed   The field's GraphQL type
   * @since   0.3.0
   * @access  private
   */
  private static function get_field_graphql_type_name($field)
  {
    ['type' => $type] = $field;

    switch ($type) {
      case 'autocomplete':
      case 'button':
      case 'button_group':
      case 'divider':
      case 'file':
      case 'file_advanced':
      case 'file_input':
      case 'file_upload':
      case 'hidden':
      case 'image':
      case 'image_advanced':
      case 'image_select':
      case 'image_upload':
      case 'map':
      case 'plupload_image':
      case 'slider':
      case 'video':
        error_log(__("Unsupported Meta Box type supplied to wpgraphql-metabox: $type", 'wpgraphql-metabox'));
        return;
      case 'taxonomy':
      case 'taxonomy_advanced':
        $name = $field['taxonomy'][0];
        $taxonomy = get_taxonomy($name);

        if (empty($taxonomy) || $taxonomy->show_in_graphql === false) {
          error_log("wp-graphql-metabox: $name is not in the schema.");
          return null;
        }

        return $taxonomy->graphql_single_name;
      case 'switch':
      case 'checkbox':
        return 'Boolean';
      case 'checkbox_list':
        return ['list_of' => 'Boolean'];
      case 'background':
      case 'color':
      case 'custom_html':
      case 'date':
      case 'heading':
      case 'datetime':
      case 'oembed':
      case 'password':
      case 'radio':
      case 'textarea':
      case 'time':
      case 'select':
      case 'email':
      case 'tel':
      case 'text':
      case 'url':
      case 'wysiwyg':
        return 'String';
      case 'fieldset_text':
      case 'select_advanced':
        return ['list_of' => 'String'];
      case 'text_list':
        return ['list_of' => 'String'];
      case 'key_value':
        return ['list_of' => 'MBKeyValue'];
      case 'number':
      case 'range':
        return 'Float';
      case 'single_image':
        return 'MBSingleImage';
      case 'user':
        return 'User';
      case 'post':
        $post_type = is_array($field['post_type']) === true ? $field['post_type'][0] : $field['post_type'];
        $post_type_object = get_post_type_object($post_type);

        if (!$post_type_object || !$post_type_object->show_in_graphql) {
          error_log(__("Unknown Meta Box type supplied to wpgraphql-metabox: $post_type", 'wpgraphql-metabox'));
          return;
        }

        return $post_type_object->graphql_single_name;
      case 'group':
        [
          'graphql_name' => $graphql_name,
          'fields' => $fields,
        ] = $field;
        register_graphql_object_type($graphql_name, [
          'description'   => __("$graphql_name Group", 'wpgraphql-metabox'),
          'fields'        => array_reduce($fields, function ($fields, $field) {
            if (!key_exists('graphql_name', $field)) {
              return $fields;
            }
            [
              'graphql_name' => $graphql_name,
              'name' => $name,
              'id' => $id
            ] = $field;
            $graphql_type = self::get_graphql_type($field);
            $fields[$graphql_name]  = [
              'type' => $graphql_type,
              'description' => $name,
              'resolve' => function ($node) use ($id) {
                return $node[$id];
              },
              'args' => self::get_graphql_field_args($graphql_type),
            ];

            return $fields;
          }, [
            'id' => [
              'type' => 'ID',
              'description' => __('Generated ID', 'wpgraphql-metabox'),
              'resolve' => function ($node) use ($graphql_name) {
                return Relay::toGlobalId($graphql_name, hash('md5', json_encode($node)));
              }
            ]
          ]),
        ]);

        return $graphql_name;
      default:
        error_log(__("Unknown Meta Box type supplied to wpgraphql-metabox: $type", 'wpgraphql-metabox'));
    }
  }

  /**
   * Executes the field resolver callback function for single, multiple and cloned fields
   *
   * @param   array     The metabox field configuration
   * @param   function  The field type resolver
   * @return  mixed     The resolved data
   * @since   0.3.0
   * @access  private
   */
  private static function get_resolved_field_value($field_config, $field_resolver)
  {
    // cloned or multiple field
    if (is_array($field_config)) {
      return array_map(function ($field_data) use ($field_resolver) {
        // cloned and multiple field
        if (is_array($field_data)) {
          return array_map($field_resolver, $field_data);
        }
        // cloned xor multiple field
        return $field_resolver($field_data);
      }, $field_config);
    }

    // non-cloned or multiple field
    return $field_resolver($field_config);
  }

  /**
   * Registers the field as a mutation input if `graphql_mutate` is set to true in the field
   * configuration.
   * 
   * Untested with non-scalar fields
   *
   * @param string  $graphql_singular_name  The GraphQL singular name to mutate
   * @param array   $graphql_field_type     The GraphQL field type    
   * @param array   $field_config           The metabox field configuration
   * @since  0.5.0
   * @access public
   */
  public static function register_mutation_input($graphql_singular_name, $graphql_field_type, $field_config)
  {
    if (
      !key_exists('graphql_mutate', $field_config) ||
      $field_config['graphql_mutate'] !== true
    ) {
      // users have not asked to register this field as a mutation input
      return;
    }

    // add field as input (untested with non-scalar fields)
    add_filter('graphql_input_fields', function ($fields, $type_name) use ($graphql_singular_name, $graphql_field_type, $field_config) {
      if ($type_name === "Create{$graphql_singular_name}Input" || $type_name === "Update{$graphql_singular_name}Input") {
        ['name' => $name, 'graphql_name' => $graphql_name] = $field_config;
        $fields[$graphql_name] = [
          'type'          => $graphql_field_type,
          'description'   => $name,
        ];
      }
      return $fields;
    }, 10, 2);

    // update the meta value
    add_action('graphql_post_object_mutation_update_additional_data', function (int $post_id, array $input) use ($field_config) {
      ['id' => $id, 'graphql_name' => $graphql_name] = $field_config;
      rwmb_set_meta($post_id, $id, $input[$graphql_name]);
    }, 10, 3);
  }
}
