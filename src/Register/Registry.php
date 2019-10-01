<?php
namespace TypeRocket\Register;

use Closure;
use TypeRocket\Core\Config;
use TypeRocket\Models\Model;
use TypeRocket\Models\WPPost;
use WP_Post;
use WP_Query;
use WP_Term;

class Registry
{

    public static $collection = [];
    public static $aggregateCollection = [];

    public static $postTypes = [
        'post' => ['post', 'posts', null, null],
        'page' => ['page', 'pages', null, null]
    ];

    public static $taxonomies = [
        'category' => ['category', 'categories', null, null],
        'post_tag' => ['tag', 'tags', null, null]
    ];

    public static $customs = [];

    /**
     * Add a post type resource
     *
     * @param string $id post type id
     * @param array $resource resource name ex. posts, pages, books
     */
    public static function addPostTypeResource($id, $resource = []) {
        self::$postTypes[$id] = array_pad($resource, 4, null);
    }

    /**
     * Get the post type resource
     *
     * @param string $id
     *
     * @return null
     */
    public static function getPostTypeResource($id) {
        return ! empty(self::$postTypes[$id]) ? self::$postTypes[$id] : null;
    }

    /**
     * Get the taxonomy resource
     *
     * @param string $id
     *
     * @return null
     */
    public static function getTaxonomyResource($id) {
        return ! empty(self::$taxonomies[$id]) ? self::$taxonomies[$id] : null;
    }

    /**
     * Add a taxonomy resource
     *
     * @param string $id post type id
     * @param array $resource resource name ex. posts, pages, books
     */
    public static function addTaxonomyResource($id, $resource = []) {
        self::$taxonomies[$id] = array_pad($resource, 4, null);
    }

    /**
     * Add a custom resource
     *
     * @param string $id custom resource id
     * @param array $resource resource name ex. posts, pages, books
     */
    public static function addCustomResource($id, $resource = []) {
        self::$customs[$id] = array_pad($resource, 4, null);
    }

    /**
     * Get the custom resource
     *
     * @param string $id
     *
     * @return null
     */
    public static function getCustomResource($id) {
        return self::$customs[$id] ?? null;
    }

    /**
     * Add Registrable objects to collection
     *
     * @param null|Registrable|string $obj
     */
    public static function addRegistrable( $obj = null )
    {
        if ( $obj instanceof Registrable) {
            self::$collection[] = $obj;
        }
    }

    /**
     * Loop through each Registrable and add hooks automatically
     */
    public static function initHooks()
    {
        $collection = [];
        $later = [];

        if(empty(self::$collection)) {
            return;
        }

        foreach(self::$collection as $obj) {
            if ( $obj instanceof Registrable) {
                $collection[] = $obj;
                $use = $obj->getApplied();
                foreach($use as $objUsed) {
                    if( ! in_array($objUsed, $collection) && ! $objUsed instanceof Page) {
                        $later[] = $obj;
                        array_pop($collection);
                        break 1;
                    }
                }

                if ($obj instanceof Page && ! empty( $obj->parent ) ) {
                    $later[] = $obj;
                    array_pop($collection);
                }
            }
        }
        $collection = array_merge($collection, $later);

        foreach ($collection as $obj) {
            if ($obj instanceof Taxonomy) {
                add_action( 'init', [$obj, 'register']);
            } elseif ($obj instanceof PostType) {
                /** @var PostType $obj */
                add_action( 'init', [$obj, 'register']);
            } elseif ($obj instanceof MetaBox) {
                add_action( 'admin_init', [$obj, 'register']);
                add_action( 'add_meta_boxes', [$obj, 'register']);
            } elseif ($obj instanceof Page) {
                if($obj->useController) {
                    add_action( 'admin_init', [$obj, 'respond'] );
                }

                add_action( 'admin_menu', [$obj, 'register']);
            }
        }

        add_action( 'init', function() {
            self::setAggregatePostTypeHooks();
        });
    }

    /**
     * Taxonomy Hooks
     *
     * @param Taxonomy $obj
     */
    public static function taxonomyHooks(Taxonomy $obj)
    {
        self::taxonomyFormContent($obj);

        if($custom_templates = $obj->getTemplates()) {
            foreach(['taxonomy', 'category', 'tag'] as $template_hook) {
                add_filter($template_hook . '_template', Closure::bind(function($template) use ($custom_templates) {
                    /** @var WP_Term $term */
                    $term = get_queried_object();

                    if($term->taxonomy == $this->getId()) {
                        $template = $custom_templates['archive'];
                    }

                    return $template;
                }, $obj), 0, 1);
            }
        }
    }

    /**
     * Post Type Hooks
     *
     * @param PostType $obj
     */
    public static function postTypeHooks(PostType $obj)
    {
        if (is_string( $obj->getTitlePlaceholder() )) {
            add_filter( 'enter_title_here', function($title) use ($obj) {
                global $post;

                if(!empty($post)) {
                    if ( $post->post_type == $obj->getId() ) {
                        return $obj->getTitlePlaceholder();
                    }
                }

                return $title;

            } );
        }

        if( !empty($obj->getArchiveQuery()) ) {
            add_action('pre_get_posts', Closure::bind(function( WP_Query $main_query ) {
                if($main_query->is_main_query() && $main_query->is_post_type_archive($this->getId())) {
                    $query = $this->getArchiveQuery();
                    foreach ($query as $key => $value) {
                        $main_query->set($key, $value);
                    }
                }
            }, $obj));
        }

        if($custom_templates = $obj->getTemplates()) {
            foreach(['single', 'archive', 'page'] as $template_hook) {
                if(!empty($custom_templates[$template_hook])) {
                    add_filter($template_hook . '_template', Closure::bind(function($template, $type) use ($custom_templates) {
                        /** @var WP_Post $post */
                        global $post;

                        if($post->post_type == $this->getId()) {
                            $template = $custom_templates[$type];
                        }

                        return $template;
                    }, $obj), 0, 2);
                }
            }
        }

        if($obj->getRootSlug()) {
            self::$aggregateCollection['post_type']['root_slug'][] = $obj->getId();
        }

        self::setPostTypeColumns($obj);
        self::postTypeFormContent($obj);
    }

    /**
     * Add taxonomy form hooks
     *
     * @param Taxonomy $obj
     */
    public static function taxonomyFormContent( Taxonomy $obj ) {

        $callback = function( $term, $type, $obj )
        {
            /** @var Taxonomy $obj */
            if ( $term == $obj->getId() || $term->taxonomy == $obj->getId() ) {
                $func = 'add_form_content_' . $obj->getId() . '_' . $type;
                echo '<div class="typerocket-container typerocket-taxonomy-style">';
                $form = $obj->getForm( $type );
                if (is_callable( $form )) {
                    call_user_func( $form, $term );
                } elseif (function_exists( $func )) {
                    call_user_func( $func, $term );
                } elseif ( Config::locate('app.debug') == true) {
                    echo "<div class=\"tr-dev-alert-helper\"><i class=\"icon tr-icon-bug\"></i> Add content here by defining: <code>function {$func}() {}</code></div>";
                }
                echo '</div>';
            }
        };

        if ($obj->getForm( 'main' )) {
            add_action( $obj->getId() . '_edit_form', function($term) use ($obj, $callback) {
                $type = 'main';
                call_user_func_array($callback, [$term, $type, $obj]);
            }, 10, 2 );

            add_action( $obj->getId() . '_add_form_fields', function($term) use ($obj, $callback) {
                $type = 'main';
                call_user_func_array($callback, [$term, $type, $obj]);
            }, 10, 2 );
        }
    }

    /**
     * Add post type form hooks
     *
     * @param PostType $obj
     */
    public static function postTypeFormContent( PostType $obj) {

        /**
         * @param WP_Post $post
         * @param string $type
         * @param PostType $obj
         */
        $callback = function( $post, $type, $obj )
        {
            if ($post->post_type == $obj->getId()) {
                $func = 'add_form_content_' . $obj->getId() . '_' . $type;
                echo '<div class="typerocket-container">';

                $form = $obj->getForm( $type );
                if (is_callable( $form )) {
                    call_user_func( $form );
                } elseif (function_exists( $func )) {
                    call_user_func( $func, $post );
                } elseif (Config::locate('app.debug') == true) {
                    echo "<div class=\"tr-dev-alert-helper\"><i class=\"icon tr-icon-bug\"></i> Add content here by defining: <code>function {$func}() {}</code></div>";
                }
                echo '</div>';
            }
        };

        // edit_form_top
        if ($obj->getForm( 'top' )) {
            add_action( 'edit_form_top', function($post) use ($obj, $callback) {
                $type = 'top';
                call_user_func_array($callback, [$post, $type, $obj]);
            } );
        }

        // edit_form_after_title
        if ($obj->getForm( 'title' )) {
            add_action( 'edit_form_after_title', function($post) use ($obj, $callback) {
                $type = 'title';
                call_user_func_array($callback, [$post, $type, $obj]);
            } );
        }

        // edit_form_after_editor
        if ($obj->getForm( 'editor' )) {
            add_action( 'edit_form_after_editor', function($post) use ($obj, $callback) {
                $type = 'editor';
                call_user_func_array($callback, [$post, $type, $obj]);
            } );
        }

        // dbx_post_sidebar
        if ($obj->getForm( 'bottom' )) {
            add_action( 'dbx_post_sidebar', function($post) use ($obj, $callback) {
                $type = 'bottom';
                call_user_func_array($callback, [$post, $type, $obj]);
            } );
        }

    }

    /**
     * Add post type admin table columns hooks
     *
     * @param PostType $post_type
     */
    public static function setPostTypeColumns( PostType $post_type)
    {
        $pt = $post_type->getId();
        $new_columns = $post_type->getColumns();
	    $primary_column = $post_type->getPrimaryColumn();

        $model = WPPost::class;

        add_action('wp_loaded', function() use (&$model, $pt) {
            $resource = Registry::getPostTypeResource($pt);
            if($resource) {
                if (class_exists($resource[2])) {
                    /** @var \TypeRocket\Models\Model|string $model */
                    $model = $resource[2];
                }
            }
        }, 9);

        add_filter( "manage_edit-{$pt}_columns" , function($columns) use ($new_columns) {
            foreach ($new_columns as $key => $new_column) {
                if($new_column == false && array_key_exists($key, $columns)) {
                    unset($columns[$key]);
                } else {
                    $columns[$new_column['field']] = $new_column['label'];
                }
            }

            return $columns;
        });

        add_action( "manage_{$pt}_posts_custom_column" , function($column, $post_id) use ($new_columns, &$model) {
            global $post;

            foreach ($new_columns as $new_column) {
                if(!empty($new_column['field']) && $column == $new_column['field']) {
                    $data = [
                        'column' => $column,
                        'field' => $new_column['field'],
                        'post' => $post,
                        'post_id' => $post_id
                    ];
                    /** @var Model $post_temp */
                    $post_temp = (new $model);
                    $value = $post_temp
                        ->setProperty($post_temp->getIdColumn(), $post_id)
                        ->getBaseFieldValue($new_column['field']);

                    call_user_func_array($new_column['callback'], [$value, $data]);
                }
            }
        }, 10, 2);

	    if( $primary_column ) {
		    add_filter( 'list_table_primary_column', function ( $default, $screen ) use ( $pt, $primary_column ) {

			    if ( $screen === 'edit-' . $pt ){
				    $default = $primary_column;
			    }

			    return $default;
		    }, 10, 2 );
	    }

        foreach ($new_columns as $new_column) {
            if(!empty($new_column['sort'])) {
                add_filter( "manage_edit-{$pt}_sortable_columns", function($columns) use ($new_column) {
                    $columns[$new_column['field']] = $new_column['field'];
                    return $columns;
                } );

                add_action( 'load-edit.php', function() use ($pt, $new_column) {
                    add_filter( 'request', function( $vars ) use ($pt, $new_column) {
                        if ( isset( $vars['post_type'] ) && $pt == $vars['post_type'] ) {
                            if ( isset( $vars['orderby'] ) && $new_column['field'] == $vars['orderby'] ) {

                                if( ! in_array($new_column['field'], (new WPPost())->getBuiltinFields())) {
                                    if(!empty($new_column['order_by'])) {

                                        switch($new_column['order_by']) {
                                            case 'number':
                                            case 'num':
                                            case 'int':
                                                $new_vars['orderby'] = 'meta_value_num';
                                                break;
                                            case 'decimal':
                                            case 'double':
                                                $new_vars['orderby'] = 'meta_value_decimal';
                                                break;
                                            case 'date':
                                                $new_vars['orderby'] = 'meta_value_date';
                                                break;
                                            case 'datetime':
                                                $new_vars['orderby'] = 'meta_value_datetime';
                                                break;
                                            case 'time':
                                                $new_vars['orderby'] = 'meta_value_time';
                                                break;
                                            case 'string':
                                            case 'str':
                                                break;
                                            default:
                                                $new_vars['orderby'] = $new_column['order_by'];
                                                break;
                                        }
                                    }
                                    $new_vars['meta_key'] = $new_column['field'];
                                } else {
                                    $new_vars = [ 'orderby' => $new_column['field'] ];
                                }

                                $vars = array_merge( $vars, $new_vars );
                            }
                        }

                        return $vars;
                    });
                } );
            }
        }
    }

    /**
     * Run agrogate
     */
    public static function setAggregatePostTypeHooks()
    {
        /**
         * Post Type Hooks
         */
        $root_slugs = self::$aggregateCollection['post_type']['root_slug'] ?? [];

        if(!$root_slugs) {
            return;
        }

        add_filter( 'post_type_link', function ( $post_link, $post ) use ($root_slugs) {
            if ( in_array($post->post_type, $root_slugs) && 'publish' === $post->post_status ) {
                $post_link = str_replace( '/' . $post->post_type . '/', '/', $post_link );
            }
            return $post_link;
        }, 10, 2 );

        add_action( 'pre_get_posts', function ( $query ) use ($root_slugs) {
            /** @var WP_Query $query */
            if ( ! $query->is_main_query() ) {
                return;
            }

            if ( ! isset( $query->query['page'] ) || 2 !== count( $query->query ) ) {
                return;
            }

            if ( empty( $query->query['name'] ) ) {
                return;
            }

            $query->set( 'post_type', array_merge(['post', 'page'], $root_slugs) );
        } );

        add_filter('wp_unique_post_slug', function($slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug) use ($root_slugs) {
            global $wpdb, $wp_rewrite;

            $post_types = array_merge(['post', 'page'], $root_slugs);
            $types = "'" . implode("','", $post_types) . "'";

            if ( in_array($post_type, ['symbol', 'post', 'page']) || in_array( $slug, $wp_rewrite->feeds ) || 'embed' === $slug || apply_filters( 'wp_unique_post_slug_is_bad_flat_slug', false, $slug, $post_type ) ) {
                $suffix = 2;
                $check_sql = "SELECT post_name FROM {$wpdb->posts} WHERE post_type IN ({$types}) AND post_name = %s AND ID != %d LIMIT 1";
                do {
                    $post_name_check = $wpdb->get_var( $wpdb->prepare( $check_sql, $slug, $post_ID ) );
                    $alt_post_name   = _truncate_post_slug( $slug, 200 - ( strlen( $suffix ) + 1 ) ) . "-$suffix";

                    if($post_name_check) {
                        $slug = $alt_post_name;
                    }

                    $suffix++;
                } while ( $post_name_check );
            }

            return $slug;

        }, 0, 6);
    }
}
