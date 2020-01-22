<?php
/**
 * Class responsible for all Settings in plugin
 */

/**
 * Class Woo_Erply_Settings
 */
class Woo_Erply_Settings {
    public $textdomain;
    public $default_active_tab;
    public $settings_slug = 'woo_erply_integration';
    public $tabs;
    public $cred_fields;
    public $erply_countries = [];
    // Default cache time in SECONDS for addressTypes, vatRates, configParameters from Erply
    public $cache_time = 3600;

    public function __construct( $args ) {
        if ( !empty( $args["textdomain"] ) ) $this->textdomain = $args["textdomain"];

        $this->tabs = [
			["slug" => 'woo_erply_creds', "label" => __("Preferences", $this->textdomain)],
            ["slug" => 'woo_erply_sync', "label" => __("Manual sync", $this->textdomain)],
            ["slug" => 'woo_erply_logs', "label" => __("Logs / Errors", $this->textdomain)],
        ];

        $this->cred_fields = [
			"erply_customer_code" => [
				"key"      => "erply_customer_code",
				"label"    => __("Erply Customer Code", $this->textdomain),
				"type"     => "text",
				"required" => true,
				"hide_val" => false,
			],
            "erply_username" => [
                "key"      => "erply_username",
                "label"    => __("Erply User Name", $this->textdomain),
                "type"     => "text",
                "required" => true,
                "hide_val" => false,
            ],
            "erply_password" => [
                "key"      => "erply_password",
                "label"    => __("Erply Password", $this->textdomain),
                "type"     => "password",
                "required" => true,
                "hide_val" => false,
            ],
        ];

        $this->default_active_tab = $this->tabs[0]['slug'];

        // Register settings page
        add_action( 'admin_menu', array( $this, 'woo_erply_settings_page' ), 9999 );
        // Ajax callback for getting current Erply-Woo synchronization status
        add_action( 'wp_ajax_get_current_sync_status', array( $this, "get_current_sync_status" ) );
        add_action( 'wp_ajax_nopriv_get_current_sync_status', array( $this, "get_current_sync_status" ) );
        // Callback for refresh erply lists actions
        add_action( 'wp_ajax_refreshWarehouses', array( $this, "refresh_warehouses" ) );
        add_action( 'wp_ajax_refreshProductGroups', array( $this, "refresh_product_groups" ) );
        // Purge cache of frequently used requests
        add_action( 'wp_ajax_purgeErplyCache', array( $this, "purge_cache" ) );
        // Callback for ajax request to get form for updating erply credentials
        add_action( 'wp_ajax_load_creds_form', array( $this, "load_creds_form_callback" ) );
        // Add columns to woo orders list
        add_filter( 'manage_edit-shop_order_columns', array( $this, "erply_shop_order_column" ), 9999 );
        add_action( 'manage_shop_order_posts_custom_column' , array( $this, "erply_orders_list_column_content" ), 9999, 2 );
        // Download sync logs file if all conditions met
        add_action( 'admin_init' , array( $this, "maybe_donwload_sync_logs" ), 9999, 2 );

        if ( empty( get_option( "erply_warehouses" ) ) ) {
            $this->get_allowed_warehouses();
        }

        if ( empty( get_option( "erply_product_groups" ) ) ) {
            $this->get_product_groups();
        }

        $erply_payment_types = get_option( "orders_payment_types" );
        if ( empty( $erply_payment_types ) ) {
            $this->get_erply_payment_types();
        } else {
            $this->order_payment_types = json_decode( $erply_payment_types );
        }

        $local_countries = get_option( "erply_countries" );
        if ( !empty( $local_countries ) ) {
            $this->erply_countries = json_decode( $local_countries );
        }
    }


    /**
     * Add settings page
     */
    public function woo_erply_settings_page(){
        add_submenu_page(
            'woocommerce',
            __( 'Erply Integration', $this->textdomain ),
            __( 'Erply Integration', $this->textdomain ),
            'manage_options',
            $this->settings_slug,
            array( $this, 'woo_erply_settings_page_content' )
        );
    }

    /**
     * Page content for Settings
     */
    public function woo_erply_settings_page_content(){

        if ( !current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->save_admin_settings( $_POST );

        $block_submit       = false;
        $submit_button_text = 'Save';
        $active_tab         = isset( $_GET['tab'] ) ? $_GET['tab'] : $this->default_active_tab;
        $sync_status        = get_option( "woo_erply_sync_status" );
        $cur_sync_status    = $sync_status;

        if ( $sync_status == "in_progress" ) {
            switch ( get_option( "resource_to_sync" ) ) {
                case 'products':
                    $cur_sync_status .= ' - syncing products…';
                    break;
                case 'orders':
                    $cur_sync_status .= ' - syncing orders…';
                    break;
				case 'stocks':
					$cur_sync_status .= ' - syncing stocks…';
					break;
            }

        }

        do_action('erply_notices');

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( get_admin_page_title() ); ?></h1>

            <div>Current sync status: <span id="current_sync_status"><?php esc_html_e( $cur_sync_status); ?></span></div>

            <?php $this->render_admin_settings_tabs( $active_tab ); ?>

            <form action="" method="post">
                <div id="poststuff">
                    <div class="metabox-holder columns-2">
                        <div id="submitdiv" class="postbox">
                            <div class="inside">

                                <table class="form-table ct-metabox">
                                    <tbody>
                                        <?php

                                        switch ( $active_tab ) {
                                            case "woo_erply_logs":
                                                echo $this->render_woo_erply_logs_tab();
                                                $block_submit = true;
                                                break;
                                            case "woo_erply_creds":
                                                $submit_button_text = "Save";
                                                echo $this->render_woo_erply_prefs_tab();
                                                break;
                                            case "woo_erply_sync":
                                            default:
                                                $submit_button_text = "Synchronize";
                                                $result             = $this->render_woo_erply_sync_tab();
                                                $block_submit       = $result["block_submit"];
                                                echo $result["html"];
                                        }

                                        ?>
                                    </tbody>
                                </table>

                            </div>
                        </div>
                    </div><!-- /post-body -->
                    <input type="hidden" name="active_tab" value="<?php esc_html_e($active_tab); ?>" />
                    <div>
                        <?php
                            if ( $active_tab == "woo_erply_sync" ) {
                                echo '<input type="submit" name="reset" id="reset" class="button button-warning" value="Reset All">';
                            }

                            if ( $active_tab == "woo_erply_logs" ) {
                                echo '<input type="submit" name="clear" id="clear" class="button button-warning" value="Clear Logs">';
                            }

                            if ( !$block_submit ) {
                                submit_button( $submit_button_text, 'primary', 'submit', false );
                            }
                        ?>
                    </div>
                    <br class="clear">
                </div><!-- /poststuff -->
            </form>
        </div>

        <style>
            tr.ct-field th {
                padding-left: 15px;
            }

            .form-table tr.ct-field:last-child>th,
            .form-table tr.ct-field:last-child>td {
                border-bottom: none
            }

            .ct-metabox tr.ct-field {
                border-top: 1px solid #ececec
            }

            .ct-metabox tr.ct-field:first-child {
                border-top: 0
            }

            .italic{
                font-style: italic;
            }

            tr.ct-field textarea {
                height: 100px;
            }
            div.postbox {
                width: 60%;
            }
            @media screen and (max-width: 782px) {
                .form-table td input,
                .form-table td select {
                    margin: 0
                }
                .ct-metabox tr.ct-field>th {
                    padding-top: 15px
                }
                .ct-metabox tr.ct-field>td {
                    padding-bottom: 15px
                }
            }
            .button.button-warning {
                color: #fff;
                border-color: #e03c31;
                background: #e03c31;
                box-shadow: 0 1px 0 #e03c31;
                vertical-align: top;
                margin-right: 10px;
            }
            .button.button-warning:hover,
            .button.button-warning:focus {
                color: #fff;
                border-color: #fe2712;
                background: #fe2712;
                box-shadow: 0 1px 0 #fe2712;
                vertical-align: top;
                margin-right: 10px;
            }
            <?php if ( $active_tab == "woo_erply_fields" ): ?>
                table.form-table td,
                table.form-table th {
                    padding: 15px;
                    border: 1px solid #ccc;
                }
                .postbox {
                    border: none;
                    box-shadow: none;
                }
            <?php endif; ?>
            <?php if ( $active_tab == "woo_erply_logs" ): ?>
                #woo_erply_logs {
                    width: 100%;
                    height: auto;
                    min-height: 300px;
                }
                #submitdiv {
                    width: 100%;
                }
            <?php endif; ?>

			/* Base styles for the element that has a tooltip */
			a.tooltip-icon {
				text-decoration: none;
				width: 20px;
				border-radius: 10px;
				background: black;
				color: white;
				display: block;
				text-align: center;
				font-size: 15px;
				font-weight: 900;
			}

			[data-tooltip],
			.tooltip {
				position: relative;
				cursor: pointer;
			}

			/* Base styles for the entire tooltip */
			[data-tooltip]:before,
			[data-tooltip]:after,
			.tooltip:before,
			.tooltip:after {
				position: absolute;
				visibility: hidden;
				-ms-filter: "progid:DXImageTransform.Microsoft.Alpha(Opacity=0)";
				filter: progid:DXImageTransform.Microsoft.Alpha(Opacity=0);
				opacity: 0;
				-webkit-transition:
					opacity 0.2s ease-in-out,
					visibility 0.2s ease-in-out,
					-webkit-transform 0.2s cubic-bezier(0.71, 1.7, 0.77, 1.24);
				-moz-transition:
					opacity 0.2s ease-in-out,
					visibility 0.2s ease-in-out,
					-moz-transform 0.2s cubic-bezier(0.71, 1.7, 0.77, 1.24);
				transition:
					opacity 0.2s ease-in-out,
					visibility 0.2s ease-in-out,
					transform 0.2s cubic-bezier(0.71, 1.7, 0.77, 1.24);
				-webkit-transform: translate3d(0, 0, 0);
				-moz-transform:    translate3d(0, 0, 0);
				transform:         translate3d(0, 0, 0);
				pointer-events: none;
			}

			/* Show the entire tooltip on hover and focus */
			[data-tooltip]:hover:before,
			[data-tooltip]:hover:after,
			[data-tooltip]:focus:before,
			[data-tooltip]:focus:after,
			.tooltip:hover:before,
			.tooltip:hover:after,
			.tooltip:focus:before,
			.tooltip:focus:after {
				visibility: visible;
				-ms-filter: "progid:DXImageTransform.Microsoft.Alpha(Opacity=100)";
				filter: progid:DXImageTransform.Microsoft.Alpha(Opacity=100);
				opacity: 1;
			}

			/* Base styles for the tooltip's directional arrow */
			.tooltip:before,
			[data-tooltip]:before {
				z-index: 1001;
				border: 6px solid transparent;
				background: transparent;
				content: "";
			}

			/* Base styles for the tooltip's content area */
			.tooltip:after,
			[data-tooltip]:after {
				z-index: 1000;
				padding: 8px;
				width: 220px;
				background-color: #000;
				background-color: hsla(0, 0%, 20%, 0.9);
				color: #fff;
				content: attr(data-tooltip);
				font-size: 14px;
				line-height: 1.4;
				font-weight: 400;
			}

			/* Directions */

			/* Top (default) */
			[data-tooltip]:before,
			[data-tooltip]:after,
			.tooltip:before,
			.tooltip:after,
			.tooltip-top:before,
			.tooltip-top:after {
				bottom: 100%;
				left: 50%;
			}

			[data-tooltip]:before,
			.tooltip:before,
			.tooltip-top:before {
				margin-left: -6px;
				margin-bottom: -12px;
				border-top-color: #000;
				border-top-color: hsla(0, 0%, 20%, 0.9);
			}

			/* Horizontally align top/bottom tooltips */
			[data-tooltip]:after,
			.tooltip:after,
			.tooltip-top:after {
				margin-left: -80px;
			}

			[data-tooltip]:hover:before,
			[data-tooltip]:hover:after,
			[data-tooltip]:focus:before,
			[data-tooltip]:focus:after,
			.tooltip:hover:before,
			.tooltip:hover:after,
			.tooltip:focus:before,
			.tooltip:focus:after,
			.tooltip-top:hover:before,
			.tooltip-top:hover:after,
			.tooltip-top:focus:before,
			.tooltip-top:focus:after {
				-webkit-transform: translateY(-12px);
				-moz-transform:    translateY(-12px);
				transform:         translateY(-12px);
			}
        </style>

        <script type="text/javascript">
            function refreshErplyLists( action, target_id, er_text ){
                jQuery.ajax({
                    method: "POST",
                    url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                    data: {action: action},
                    success: function( res ){
                        var tar_id    = "#" + target_id;
                        var $target   = jQuery(tar_id);
                        var er_option = '<option value="">' + er_text + '</option>';

                        if (res === "Cache successfully purged"){
                            window.location.reload();
                        }

                        if ( res.length > 0 ) {
                            var jsn = JSON.parse(res);
                        }

                        if ( typeof jsn !== "undefined" ) {
                            var options = '';

                            for ( var key in jsn ) {
                                if ( !jsn.hasOwnProperty(key) ) { continue; }

                                options = options + '<option value="' + key + '">' + jsn[key] + '</option>';
                            }

                            if ( options.length < 1 ) {
                                options = er_option;
                            }

                            $target.html( options );
                        } else {
                            $target.html( er_option );
                        }
                    }
                });
            }

            function purgeCache() {
                refreshErplyLists( "purgeErplyCache", "woo_erply_purge_cache", "" );
            }

            function refreshWarehouses(){
                refreshErplyLists( "refreshWarehouses", "woo_erply_sync_warehouse", "No Warehouses Available" );
            }

            function refreshProductGroups(){
                refreshErplyLists( "refreshProductGroups", "woo_erply_sync_products_group", "No Product Group" );
            }

            function loadCredsForm(){
                if ( !confirm('Warning! Current synchronization will be stopped after credentials change.') ) {
                    return false;
                }

                jQuery('#change_creds_input').val(1);

                jQuery.ajax({
                    method: "POST",
                    url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                    data: {action: "load_creds_form"},
                    success: function( res ){
                        jQuery("#change_creds").closest('tr').replaceWith( res );
                    }
                });
            }

            function get_current_sync_status(){
                jQuery.ajax({
                    method: "POST",
                    url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                    data: {action: "get_current_sync_status"},
                    success: function( res ){
                        jQuery("#current_sync_status").html( res );
                    }
                });
            }

            jQuery(document).ready( function(){
                setInterval( function(){ get_current_sync_status(); }, 10000 );
            } );

            jQuery("#clear").on("click", function(e){
                e.preventDefault();

                if ( confirm('This action will permanently clear all data from log file.') ) {
                    jQuery("#clear").unbind( "click" );
                    jQuery("#clear").click();
                }
            });

            jQuery("#reset").on("click", function(e){
                e.preventDefault();

                if ( confirm('All data regarding erply synchronization will be cleared from attributes, products and variations.') ) {
                    jQuery("#reset").unbind( "click" );
                    jQuery("#reset").click();
                }
            });

            function downloadSyncLogs(){
                var option = jQuery("#download_woo_erply_sync_logs").val();

                if ( typeof option !== "undefined" && option.length > 0 ) {
                    var link = window.location + "&download_woo_erply_sync_logs=" + option;

                    Object.assign(document.createElement('a'), {
                        target: '_blank',
                        href: link
                    }).click();
                }
            }
        </script>
        <?php
    }

    /**
     * Form up synchronization tab content
     *
     * @return array | bool block_submit indicates whether to hide(if true) the submit button, string html with tab fields
     */
    public function render_woo_erply_sync_tab(){
        $result = ["block_submit" => false, "html" => ""];

        $field = [
            "key"      => "resource_to_sync",
            "label"    => __( "Select resources to synchronize", $this->textdomain ),
            "type"     => "select",
            "options"  => [
                "products" => "Products",
                "orders"   => "Orders",
				"stocks"   => "Product stocks",
            ],
			"tooltip" => [
				"text" => "Select if you would like to sync orders or products to Erply. This will be the one time process and if one process is complete you can sync other after that.",
			] ,

        ];
        $result["html"] .= $this->render_admin_settings_field( $field );

        $field = [
            "key"      => "synchronize_orders_as",
            "label"    => __( "Synchronize orders as", $this->textdomain ),
            "type"     => "select",
            "options"  => [
                "ORDER"      => "ORDER",
                "INVWAYBILL" => "INVOICE WAYBILL ",
            ],
			"tooltip" => [
				"text" => "Select document type to save all existing Woocommerce orders as. You can choose either as Invoice-Waybills in Erply, which means the order automatically affects the inventory value in the warehouse, or as Orders in Erply, which means you have to manually confirm them to affect the inventory.",
				"link" => "https://help.erply.com/the-back-office/sales/sales-order-process",
			] ,

        ];
        $result["html"] .= $this->render_admin_settings_field( $field );

        return $result;
    }

    /**
     * Form up preferences tab content
     *
     * @return string
     */
    public function render_woo_erply_prefs_tab(){
        $change_creds = 0;
        $html = '';
		$html .= '<tr><td><h3>Credentials</h3></td></tr>';

        if ( $this->check_erply_credentials() ) {
            $html .= '<tr class="form-field ct-field"><th scope="row" valign="top"><label>Credentials are saved.</label></th>';
            $html .= '<td><button id="change_creds" class="button button-primary" type="button" onclick="loadCredsForm();">Change credentials</button></td></tr>';
        } else {
            $html .= $this->render_credentials_form();
            $change_creds = 1;
        }

        $html .= '<tr style="visibility: hidden;"><input type="hidden" name="change_creds" id="change_creds_input" value="'.$change_creds.'" /></tr>';

        $html .= '<tr><td><h3>Immediate order sync settings</h3></td></tr>';

		$field = [
			"key"   => "sync_orders_immediately",
			"label" => __( "Synchronize new orders immediately", $this->textdomain ),
			"type"  => "checkbox",
		];
		$html .= $this->render_admin_settings_field( $field );

		$field = [
			"key"      => "save_immediately_synced_orders_as",
			"label"    => __( "Save immediately-synced orders as", $this->textdomain ),
			"type"     => "select",
			"options"  => [
				"order"   => "Order",
				"invoice" => "Invoice",
			],
			"tooltip" => [
				"text" => "Select document type to save new Woocommerce orders as. You can choose either as Invoice-Waybills in Erply, which means the order automatically affects the inventory value in the warehouse, or as Orders in Erply, which means you have to manually confirm them to affect the inventory.",
				"link" => "https://help.erply.com/the-back-office/sales/sales-order-process",
			] ,
		];
		$html .= $this->render_admin_settings_field( $field );

		$html .= '<tr><td><h3>General settings</h3></td></tr>';


		$warehouses = get_option( "erply_warehouses" );

		if ( !empty( $warehouses ) ) {
			$field = [
				"key"      => "woo_erply_sync_warehouse",
				"label"    => __( "Select warehouse", $this->textdomain ),
				"type"     => "select",
				"options"  => json_decode( $warehouses ),
				"button"   => [
					"text"     => "Refresh Warehouses",
					"callback" => "refreshWarehouses();",
					"class"    => "button button-primary",
				],
				"tooltip" => [
					"text" => "Select which warehouse would you like to sync your orders and products to. You can either use the same warehouse as your store or you can create a new warehouse for separate stock management.",
				] ,
			];
			$html .= $this->render_admin_settings_field( $field );
		} else {
			$field = [
				"notice" => __( "Synchronization is not possible because there are no available warehouses", $this->textdomain ),
				"type"   => "notice",
				"button" => [
					"text"     => "Refresh Warehouses",
					"callback" => "refreshWarehouses();",
					"class"    => "button button-primary",
				],
				"tooltip" => [
					"text" => "Select which warehouse would you like to sync your orders and products to. You can either use the same warehouse as your store or you can create a new warehouse for separate stock management.",
				],
			];
			$html .= $this->render_admin_settings_field( $field );
		}

		$product_groups = get_option( "erply_product_groups" );

		if ( !empty( $product_groups ) ) {
			$field = [
				"key"      => "woo_erply_sync_products_group",
				"label"    => __( "Select product group", $this->textdomain ),
				"type"     => "select",
				"options"  => json_decode( $product_groups ),
				"button"   => [
					"text"     => "Refresh Product Groups",
					"callback" => "refreshProductGroups();",
					"class"    => "button button-primary",
				],
				"tooltip" => [
					"text" => "Choose the product group where you wish to import all of your products.",
					"link" => "https://help.erply.com/the-back-office/pim/product-groups",
				] ,
			];
			$html .= $this->render_admin_settings_field( $field );
		} else {
			$field = [
				"notice" => __( "Synchronization is not possible because there are no available product groups", $this->textdomain ),
				"type"   => "notice",
				"button" => [
					"text"     => "Refresh Product Groups",
					"callback" => "refreshProductGroups();",
					"class"    => "button button-primary",
				],
				"tooltip" => [
					"text" => "Choose the product group where you wish to import all of your products.",
					"link" => "https://help.erply.com/the-back-office/pim/product-groups",
				] ,
			];
			$html .= $this->render_admin_settings_field( $field );
		}

		$opt = get_option( "orders_payment_types" );

		if ( !empty( $opt ) ) {
			$field = [
				"key"      => "selected_orders_payment_type",
				"label"    => __( "Orders Payment Type", $this->textdomain ),
				"type"     => "select",
				"options"  => json_decode( $opt ),
				"tooltip" => [
					"text" => "Choose how would you like to name Woocommerce payments in Erply. This is just for the naming of payments, Woocommerce supports all payment types added to your shop.",
				] ,
			];
			$html .= $this->render_admin_settings_field( $field );
		} else {
			$field = [
				"notice" => __( "Orders synchronization is not possible because there are no available Payment Types", $this->textdomain ),
				"type"   => "notice",
			];
			$html .= $this->render_admin_settings_field( $field );
		}
		if ( get_option( 'woocommerce_calc_taxes' ) == "yes" || get_option( 'woocommerce_calc_taxes' ) == "no" ) {
			$vat_rates = $this->get_erply_vat_rates();

			if ( !empty( $vat_rates ) ) {
				$field = [
					"key" => "default_vat_rate_id",
					"label" => __("Default VAT rate", $this->textdomain),
					"type" => "select",
					"options" => $vat_rates,
				];
				$html .= $this->render_admin_settings_field($field);
			} else {
				$field = [
					"notice" => __("Orders synchronization is not possible because failed to get VAT rates", $this->textdomain),
					"type" => "notice",
				];
				$html .= $this->render_admin_settings_field($field);
			}
		}

		$address_types = $this->get_erply_address_types();

		if ( !empty( $address_types ) ) {
			$field = [
				"key"      => "erply_billing_address_type_id",
				"label"    => __( "Billing address type", $this->textdomain ),
				"type"     => "select",
				"options"  => $address_types,
			];
			$html .= $this->render_admin_settings_field( $field );

			$field = [
				"key"      => "erply_shipping_address_type_id",
				"label"    => __( "Shipping address type", $this->textdomain ),
				"type"     => "select",
				"options"  => $address_types,
			];
			$html .= $this->render_admin_settings_field( $field );
		} else {
			$field = [
				"notice" => __( "Orders synchronization is not possible because failed to get Address types", $this->textdomain ),
				"type"   => "notice",
			];
			$html .= $this->render_admin_settings_field( $field );
		}

        $field = [
            "key"      => "woo_erply_purge_cache",
            "label"    => __( "Options cache" ),
            "type"     => "hidden",
            "options"  => json_decode( $warehouses ),
            "button"   => [
                "text"     => "Purge cache",
                "callback" => "purgeCache();",
                "class"    => "button button-primary",
            ],
            "tooltip" => [
                "text" => "Empty cache which holds VAT rates, address types and config parameters coming from Erply. By default, cache lasts for 1 hour.",
            ] ,
        ];
        $html .= $this->render_admin_settings_field( $field );


        return $html;
    }

    public function load_creds_form_callback(){
        echo $this->render_credentials_form();
        wp_die();
    }

    public function render_credentials_form(){
        $html = '';

        foreach ( $this->cred_fields as $key => $field ) {
                $html .= $this->render_admin_settings_field( $field );
        }

        return $html;
    }

    /**
     * Form up settings section for credentials section
     *
     * @return string
     */
    public function render_woo_erply_logs_tab(){
        $html = '';
        $logs = Woo_Erply_Main::get_log_file_contents();

        $html .= '<tr><td colspan="3"><textarea id="woo_erply_logs">'.esc_html($logs).'</textarea></td></tr>';

        $html .= $this->render_admin_settings_field([
            "key"     => "download_woo_erply_sync_logs",
            "label"   => __( "Download logs", $this->textdomain ),
            "type"    => "select",
            "options" => [
                "day"     => "Today",
                "week"    => "Last 7 days",
                "history" => "Complete history",
            ],
        ]);

        $html .= '<tr class="form-field ct-field">
                    <th scope="row" valign="top"><label for="download_logs"></label></th>
                    <td><div>
                        <button type="button" class="button button-primary" onclick="downloadSyncLogs();">'.__( "Download", $this->textdomain ).'</button>
                    </div></td>
                  </tr>';

        return $html;
    }

    /**
     * Form up tabs html block for settings page
     *
     * @param $active_tab
     */
    private function render_admin_settings_tabs( $active_tab ){
        $tabs_html = '<h2 class="nav-tab-wrapper">';

        foreach ( $this->tabs as $tab ) {
            $active = ( $tab['slug'] == $active_tab ) ? ' nav-tab-active' : '';
            $tabs_html .= '<a href="admin.php?page='.$this->settings_slug.'&tab='.$tab['slug'].'" class="nav-tab'.$active.'">'.__( $tab["label"], $this->textdomain ).'</a>';
        }

        $tabs_html .= '</h2>';

        echo $tabs_html;
    }

    /**
     * Save settings in admin area
     *
     * @param $values
     * @return bool
     */
    public function save_admin_settings( $values ){

        switch ( $values["active_tab"] ) {
            case "woo_erply_creds":

				$options = [
					"save_immediately_synced_orders_as",
					"sync_orders_immediately",
					"woo_erply_sync_warehouse",
					"woo_erply_sync_products_group",
					"selected_orders_payment_type",
					"default_vat_rate_id",
					"erply_billing_address_type_id",
					"erply_shipping_address_type_id",
				];

				foreach ( $options as $option ) {
					if ( !empty( $_POST[$option] ) ) {
						$opt = sanitize_option($option, $_POST[$option]);
						update_option( $option, $opt);
					} else {
						// checkboxes which are not filled are assumed to be updates to zero
						update_option( $option, 0 );
					}
				}

				if ( !empty( $values["change_creds"] ) ) {

					if (!empty($values["erply_username"]) &&
						!empty($values["erply_password"]) &&
						!empty($values["erply_customer_code"])
					) {

						$customer_code = intval($values["erply_customer_code"]);
						if (strlen((string)$customer_code) > 6) {
							$message = 'Invalid customer code';
							add_action('erply_notices', function () use ($message) {
								echo '<div class="error warning is-dismissible"><p>' . esc_html( __( $message, 'text_domain' ) ) . '</p></div>';
							});
							return false;
						}

						update_option( "erply_customer_code",$customer_code);

						$username = sanitize_email($values["erply_username"]);
						$password = sanitize_text_field($values["erply_password"]);

						$sess_key_add = $this->get_session_key(
							$username,
							$password
						);

						if ($sess_key_add["success"] == true) {
							update_option( "erply_username",$username);
							update_option( "erply_password",$password);
							$message = 'Successfully logged in';
							add_action('erply_notices', function () use ($message) {
								echo '<div class="updated warning is-dismissible"><p>' . $message . '</p></div>';
							});
						} else {
							$error = '';
							if (isset($sess_key_add["error"])) {
								$error = intval($sess_key_add["error"]);
							}
							add_action('erply_notices', function () use ($error) {
								$message = 'Login failed.';
								$erply_auth_errors = array(
									1050 => "Username/password missing.",
									1051 => "Login failed.",
									1052 => "User has been temporarily blocked because of repeated unsuccessful login attempts.",
									1053 => "No password has been set for this user, therefore the user cannot be logged in.",
									1054 => "API session has expired. Please call API \"verifyUser\" again (with correct credentials) to receive a new session key.",
									1055 => "Supplied session key is invalid; session not found.",
									1056 => "Supplied session key is too old. User switching is no longer possible with this session key, please perform a full re-authentication via API \"verifyUser\".",
									1057 => "Your time-limited demo account has expired. Please create a new ERPLY demo account, or sign up for a paid account.",
									1058 => "PIN login is not supported. Provide a user name and password instead, or use the \"switchUser\" API call.",
									1059 => "Unable to detect your user group.",
								);
								if (array_key_exists($error, $erply_auth_errors)) {
									$message .= " Reason: ".$erply_auth_errors[$error];
								}
								echo '<div class="error warning is-dismissible"><p>' . $message . '</p></div>';
							});
						}


						Woo_Erply_Flow::unschedule_synchronization();

						if (get_option("woo_erply_sync_status") == "in_progress") {
							$message = "Synchronization canceled because of credentials change.";
							$time = date("Y-m-d H:i:s");

							add_action('erply_notices', function () use ($message) {
								echo '<div class="updated warning is-dismissible"><p>' . $message . '</p></div>';
							});

							update_option("woo_erply_sync_status", "Canceled. Reason: Credentials change. Date: " . $time);

							Woo_Erply_Main::write_to_log_file($time . " - " . $message);
							Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");
						}
					} else {
						$message = "Each erply credential must be filled.";

						add_action('erply_notices', function () use ($message) {
							echo '<div class="updated warning is-dismissible"><p>' . $message . '</p></div>';
						});

						return false;
					}
				}

                break;
            case "woo_erply_sync":

                if ( !empty( $_POST["reset"] ) ) {
                    $this->clear_all_sync_values();
                } else {
                    update_option( "woo_erply_sync_product_page", 1 );
                    update_option( "woo_erply_sync_status", "in_progress" );

                    $options = [
                        "resource_to_sync",
                        "synchronize_orders_as",
                    ];
					apply_filters( 'sanitize_option_resource_to_sync', 'products', 'resource_to_sync' );
					apply_filters( 'sanitize_option_resource_to_sync', 'orders', 'resource_to_sync' );
					apply_filters( 'sanitize_option_resource_to_sync', 'stocks', 'resource_to_sync' );
					apply_filters( 'sanitize_option_synchronize_orders_as', 'ORDER', 'synchronize_orders_as' );
					apply_filters( 'sanitize_option_synchronize_orders_as', 'INVWAYBILL', 'synchronize_orders_as' );


					foreach ( $options as $option ) {
                        if ( !empty( $_POST[$option] ) ) {
							$opt = sanitize_option($option, $_POST[$option]);
							update_option( $option, $opt);
                        }
                    }

                    do_action( "woo_erply_sync" );
                }

                break;
            case "woo_erply_logs":

                if ( !empty( $_POST["clear"] ) ) {
                    Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - Logs cleared. Responsible user ID: " . get_current_user_id(), false );
                    Woo_Erply_Main::write_to_log_file( "- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -" );
                }

                break;
            default:
                break;
        }

        if ( !empty( $notice ) ) {
            add_action('erply_notices', function () use ($notice) {
                echo $notice;
            });
        }

        return true;
    }

    /**
     * Get value of setting option by key
     *
     * @param $key
     * @return mixed|string
     */
    public function get_setting_value( $key ){
        $option = '';
        if ( empty( $option ) ) {
            $option = get_option( $key, '' );
        }

        return html_entity_decode( $option );
    }


    /**
     * Form up html row for each individual settings field passed to this function
     *
     * @param array $field
     * @param string $class
     * @return string
     */
    public function render_admin_settings_field( $field, $class = '' ){
        $option = ( empty( $field["hide_val"] ) ) ? $this->get_setting_value( $field["key"] ) : "";

        $html = '<tr class="form-field ct-field'.$class.'">';

        if ( $field["type"] == 'notice' ) {
            $callspan = ( empty( $field["button"] ) ) ? 3 : 2;
            $html .= '<td colspan="'.$callspan.'">';
            $html .= '<p class="italic">'.$field["notice"].'</p>';
            $html .= '</td>';
        } else {
            $td    = ( empty( $field["button"] ) ) ? '<td colspan="2">' : '<td>';
            $html .= '<th scope="row" valign="top">';
            $html .= '<label for="' . $field["key"] . '">' . $field["label"] . '</label>';
            $html .= '</th>'.$td.'<div>';

            $req = ( !empty( $field["required"] ) ) ? ' required' : '';

            switch ( $field["type"] ) {
                case 'checkbox':
                    $checked = ($option) ? 'checked="checked" ' : '';
                    $html .= '<input type="hidden" name="' . $field["key"] . '" value="0" ' . $checked . $req .'/>';
                    $html .= '<input type="checkbox" id="' . $field["key"] . '" name="' . $field["key"] . '" value="1" ' . $checked . $req .'/>';
                    break;
                case 'select':
                    $html .= '<select name="' . $field["key"] . '" id="' . $field["key"] . '"' . $req . '>';
                    foreach ( $field["options"] as $val => $name) {
                        $checked = ($option == $val) ? 'selected="selected" ' : '';
                        $html .= '<option value="'.esc_html($val).'" '.$checked.'>'.esc_html($name).'</option>';
					}
                    $html .= '</select>';
                    break;
                case 'text':
                default:
                    $html .= '<input type="'.esc_html($field["type"]).'" id="' . esc_html($field["key"]) . '" name="' . esc_html($field["key"]) . '" value="' . esc_html($option) . '"' . $req . '/>';
                    break;
            }

            $html .= '</div></td>';
        }

        if ( !empty( $field["button"] ) ) {
            $html .= '<td>';
            $html .= '<button type="button" class="'.$field["button"]["class"] .'" onclick="'.$field["button"]["callback"] .'">'.$field["button"]["text"] .'</button>';
            $html .= '</td>';
        }
		if ( !empty( $field["tooltip"] ) ) {
			$html .= '<td>';
			!(empty($field["tooltip"]["link"])) && $field["tooltip"]["text"] .= " Click to read more.";
			!(empty($field["tooltip"]["link"])) ? $link = $field["tooltip"]["link"] : $link = '';
		 	$html .= '<a href="'.$link.'"  target="_blank" class="tooltip-icon" data-tooltip="'.$field["tooltip"]["text"] .'">?</span>';
			$html .= '</td>';
		}

        $html .= '</tr>';

        return $html;
    }

    /**
     * Ajax callback to check for synchronization status
     */
    public function get_current_sync_status(){
        $sync_status     = get_option( "woo_erply_sync_status" );
        $cur_sync_status = $sync_status;

        if ( $sync_status == "in_progress" ) {
            switch ( get_option( "resource_to_sync" ) ) {
                case 'products':
                    $cur_sync_status .= ' - syncing products…';
                    break;
                case 'orders':
                    $cur_sync_status .= ' - syncing orders…';
                    break;
				case 'stocks':
					$cur_sync_status .= ' - syncing product stocks…';
					break;
            }

        }

        echo $cur_sync_status;

        wp_die();
    }


	/**
	 * Check if expiration is a valid unix timestamp in the future
	 *
	 * @param int $exp
	 * @return bool
	 *
	 */
	protected function expiration_is_valid($exp) {
		$exp = intval($exp);
		$now = time();
		if ($now < $exp) {
			return true;
		} else {
			return false;
		}
	}

/**
 * Get session key from storage, Erply, or fail if not possible
 *
 * @param string|bool $username
 * @param string|bool $password
 * @return array
 *
 */

protected function get_session_key($username = false, $password = false)	{
		$credentials = array();
		$credentials["request"] = "verifyUser";
		$credentials["username"] = get_option( "erply_username" );
		$credentials["password"] = get_option( "erply_password" );
		if ($username !== false) {
			$credentials["username"] = $username;
		}
		if ($password !== false) {
			$credentials["password"] = $password;
		}
		if (($username !== false) || ($password !== false) ) {
			delete_option( "erply_api_key");
			delete_option( "erply_session_key_expires");
		}

		$return = array();


		if( ( $username ||
			  $password ||
			 !get_option("erply_api_key")) ||
			 (!get_option("erply_session_key_expires")) ||
			 (get_option("erply_session_key_expires") < time())
		) {

			$response = $this->send_request_to_erply($credentials);

			//check failure
			if ((!$response["success"]) || (!isset($response['response']->records[0]->sessionKey))) {
				$return["success"] = false;
				if (isset($response['response']->status->errorCode )) {
					$return["error"] = $response['response']->status->errorCode;
				}
			} else {
				$return["success"] = true;
				$api_key = $response['response']->records[0]->sessionKey;
				$api_key_expires = time() + $response['response']->records[0]->sessionLength - 30;
				$api_key_expires = intval($api_key_expires);
				if ($this->expiration_is_valid($api_key_expires)) {
					update_option( "erply_api_key", sanitize_text_field($api_key));
					update_option( "erply_session_key_expires", $api_key_expires);
					$return["sessionKey"] = $api_key;
				} else {
					$return["success"] = false;
				}

			}

		} else {
			$return["success"] = true;
			$return["sessionKey"] = get_option("erply_api_key");
		}

		return $return;
	}


    /**
     * Perform a curl request to erply
     *
     * @param mixed|string $body
     * @return array
     */
    public function send_curl_request( $body = [] ){

        $clientCode = get_option("erply_customer_code");
        if ($clientCode == false) {
			$result["response"] = false;
			$result["error"] = "Erply customer code not found";
			return $result;
		}
        $url = "https://".$clientCode.".erply.com/api/";

        $request = array();
        $request = array_merge($request, $body);
		$request["clientCode"] = $clientCode;

		// When calling verifyUser, we do not yet have an api key
        if ((isset($request["request"]) && $request["request"] !== "verifyUser") || (isset($request["requests"]))) {
			$sess_key = $this->get_session_key();
			$request['sessionKey'] = $sess_key["sessionKey"];
		}

        // Bulk request body must be wp_json_encoded
        if (!empty($request) && isset($request["requests"])) {
			$request["requests"] = wp_json_encode($request["requests"]);
		}

		$args = array(
			'body' => $request,
			'timeout' => '45',
			'blocking' => true,
			'redirection' => 0,
			'headers' => array(),
			'cookies' => array(),
			'reject_unsafe_url' => true,
		);

		$response = wp_safe_remote_post( $url, $args );

		$result = array();
		$result["success"] = false;

		if( is_wp_error( $response ) ) {
			$result["error"] = $response->get_error_message();
			$result["success"] = false;
		} else {
			// Code 429 indicates that too many requests have been made in last 30 seconds. See https://learn-api.erply.com/getting-started/limits for more details.
			if ( $result["response"]["code"] == 429) {
				$result["success"] = false;
				$result["code"] = 429;
			} else {
				$result["response"] = json_decode($response["body"]);
				$result["success"] = true;
			}

		}

        return $result;
    }

    /**
     * Validate service engine API response, wright data to log file, output notice message
     *
     * @param array $result - result of request to service engine API
     * @return bool|array|string|mixed
     */
    public function validate_se_api_response( $result ){

		$response     = false;
        $notice_class = "error";

        // Success is false, if general curl error occurs OR API rate limit gets busted
        if ( $result["success"] ) {
            	$message = "Failure. ";
            	// Checking errors in a successfully executed API call
                if ( $result["response"]->status->responseStatus == "ok" ) {
                    $message      = "Success.";
                    $response     = true;
                    $notice_class = "notice";
                } else {
                    if (!empty( $result["response"]->status->errorCode)) {
                        if ( $result["response"]->status->errorCode === 1002 ) {
                            Woo_Erply_Main::write_to_log_file( "Hourly API rate limit exceeded." );
                            Woo_Erply_Main::write_to_log_file( "- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -" );
                            return 1002;
                        }  else {
                            $message = "Error occurred. Error code: " . $result["response"]->status->errorCode;
                            $notice_class = "error";
                        }
                    }

                    if ( !empty( $result["response"]->status->errorMessage ) ) {
                        $message .= " " . $result["response"]->status->errorMessage;
                    } else {
                        $message .= "\n" . "Response: " . wp_json_encode( $result["response"] );
                    }
                }

                Woo_Erply_Main::write_to_log_file( $message );


            if ( $notice_class == "error" ) {
                $message .= " Check logs for details.";
                add_action('erply_notices', function () use ($message, $notice_class) {
                    echo '<div class="updated '.$notice_class.' is-dismissible"><p>' . $message . '</p></div>';
                });
                return false;
            } else {
                return $result;
            }

        } else {
            if ( !empty( $result["code"] ) && $result["code"] == 429 ) {
                Woo_Erply_Main::write_to_log_file( "429 Too Many Requests" );
                Woo_Erply_Main::write_to_log_file( "- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -" );
                return 429;
            }

            if ( !empty( $result["error"] ) ) {
                Woo_Erply_Main::write_to_log_file( $result["error"] );
            }

            Woo_Erply_Main::write_to_log_file( "Response: " . $result["response"] );

            add_action('erply_notices', function () use ($result, $notice_class) {
                echo '<div class="updated '.$notice_class.' is-dismissible"><p>' . $result["error"] . ' Check logs for details.</p></div>';
            });
        }

        Woo_Erply_Main::write_to_log_file( "- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -" );

        return $response;
    }

    /**
     * Get credentials status in Erply
     *
     * @return bool
     */
    public function check_erply_credentials(){
    	// Check for existing API key or try to fetch it using credentials
		$session_key = $this->get_session_key();

    	if ($session_key["success"]) {
    		// Check if the API key actually works for requests
			$result = $this->get_erply_conf_parameters();
			if ($result !== false) {
				Woo_Erply_Main::write_to_log_file( "Credentials are valid" );
				return true;
			}
		}

    	Woo_Erply_Main::write_to_log_file( "Credentials are not valid" );
		return false;
    }

    /**
     * Send requests to Erply
     *
     * @param $request_body
     * @return bool
     */
    public function send_request_to_erply( $request_body ){

    	if (isset($request_body["requests"])) {
			Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - "." Bulk request to Erply ");
		} else {
			Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - "." Request ".$request_body["request"]." to Erply ");
		}

 		// Use for advanced debugging etc see every requests content in logs
		// Woo_Erply_Main::write_to_log_file( "Request body: " . wp_json_encode($request_body)  );

		$result = $this->send_curl_request( $request_body );

		return $this->validate_se_api_response( $result );
    }

    /**
     * Remove all erply IDs from products and variations, from product attributes and attribute values
     */
    public function clear_all_sync_values() {

        Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - Start resetting all synchronization data. Responsible user ID: " . get_current_user_id() );


        $attributes = wc_get_attribute_taxonomies();

        foreach ( $attributes as $attribute ) {
            // Reset 'erply_dimension_id' for each attribute taxonomy
			delete_term_meta( $attribute->attribute_id, "erply_dimension_id" );

            $terms = get_terms( ['taxonomy'=>'pa_'.$attribute->attribute_name] );

            foreach ( $terms as $term ) {
                // Clear 'erply_dimension_item_id' from all attribute taxonomy values
				delete_term_meta( $term->term_id, "erply_dimension_item_id" );
            }
        }

        Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - Products attributes and attributes items cleared." );

        $query    = Woo_Erply_Flow::get_available_products( [] );
        $products = $query->get_posts();

        foreach ( $products as $product ) {
            $wc_product = wc_get_product( $product );
            // Remove 'erply_product_id' values from all products
			delete_post_meta( $wc_product->get_id(), "erply_product_id" );

            if ( $wc_product->is_type( 'variable' ) ) {
                $variations = $wc_product->get_available_variations();

                foreach ( $variations as $key => $value ) {
                    // And from each product variation
					delete_post_meta( $value["variation_id"], "erply_product_id" );
                }
            }
        }

        Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - Products and variations sync data is cleared." );


		$wc_orders = wc_get_orders([
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'type' => 'shop_order',
			'limit' => -1
		]);

		foreach ($wc_orders as $order) {
			delete_post_meta( $order->id, "erply_order_id" );
			delete_post_meta( $order->id, "erply_invoice_link" );
			delete_post_meta( $order->id, "erply_customer_id");
		}

		Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - Orders and customers sync data is cleared." );


		$coupons  = Woo_Erply_Flow::get_list_of_woo_coupons();

		foreach( $coupons as $coupon ) {
			delete_post_meta( $coupon->id, "erply_campaign_id");
		}

		Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - Coupons sync data is cleared." );


		delete_option("erply_countries");
		delete_option("last_updated_erply_countries");


		Woo_Erply_Main::write_to_log_file( date( "Y-m-d H:i:s" ) . " - Finished resetting all synchronization data." );
        Woo_Erply_Main::write_to_log_file( "- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -" );

        add_action('erply_notices', function () {
            echo '<div class="updated notice is-dismissible"><p>All synchronization data has been cleared.</p></div>';
        });
    }

    /**
     * Request available warehouses from Erply
     * Save as json string under erply_warehouses option
     *
     * @return array
     */
    public function get_allowed_warehouses(){
        $result = $this->get_items_list( "getAllowedWarehouses", "warehouseID", "name", $params = [] );


        update_option( "erply_warehouses",  wp_json_encode($result) );

        return $result;
    }

    /**
     * Request SyncApp for fresh list of available produt groups
     * Save as json string under erply_product_groups option
     *
     * @return array
     */
    public function get_product_groups() {
        $result = $this->get_items_list( "getProductGroups", "productGroupID", "name", $params = [] );

        update_option( "erply_product_groups", wp_json_encode($result) );

        return $result;
    }

    /**
     * Make request to Erply and validate response
     *
     * @param string $request
     * @param string $key
     * @param string $value
     * @param array $params
     * @return array - in $key => $value format
     */
    public function get_items_list( $request, $key, $value, $params = [] ){
        $result = [];
        $request_body = [
            "request" => $request,
            "content" => $params,
        ];
        $response = $this->send_request_to_erply($request_body);

        if ( !empty( $response["response"] ) &&
            !empty( $response["response"] ) &&
            !empty( $response["response"]->records )
        ) {
            foreach ( $response["response"]->records as $record ) {
                $result[$record->$key] = $record->$value;
            }
        } else {
            add_action('erply_notices', function () {
                echo '<div class="updated error is-dismissible"><p>Could not get response from Erply. Check logs for details.</p></div>';
            });
        }

        Woo_Erply_Main::write_to_log_file( "- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -" );

        return $result;
    }

    /**
     * Callback for ajax call performed on user's refresh product groups request
     *
     * Returns json encoded array in id => name format
     */
    public function refresh_product_groups(){
        $result = $this->get_product_groups();

        if ( !empty( $result ) ) {
            echo wp_json_encode( $result );
        }

        wp_die();
    }

    /**
     * Callback for ajax call performed on user's refresh warehouses request
     *
     * Returns json encoded array in id => name format
     */
    public function refresh_warehouses(){
        $result = $this->get_allowed_warehouses();

        if ( !empty( $result ) ) {
            echo wp_json_encode( $result );
        }

        wp_die();
    }


    /**
     * Callback for ajax call performed on user's purge cache request
     *
     */
    public function purge_cache(){
        delete_option("erply_address_types");
        delete_option("erply_conf_parameters");
        delete_option("erply_vat_rates");
        echo "Cache successfully purged";
        wp_die();
    }

    /**
     * Get erply delivery types
     *
     * @return bool
     */
    public function get_delivery_types() {
        return $this->send_request_to_erply( ["request" => "getDeliveryTypes", "content" => [],] );
    }

    /**
     * Save delivery type in erply
     *
     * @param $code
     * @param $name
     * @return bool
     */
    public function save_delivery_type( $code, $name ) {
        return $this->send_request_to_erply( ["request" => "saveDeliveryType", "code" => $code,
			"name" => $name] );
    }

    /**
     * Get erply currencies
     *
     * @return bool
     */
    public function get_erply_currencies() {
        return $this->send_request_to_erply( ["request" => "getCurrencies", "content" => [],] );
    }

    /**
     * Get list of Erply payment types [id=>type]
     *
     * @return array
     */
    public function get_erply_payment_types() {

        $paymentMethods = [];
        $result = $this->send_request_to_erply( ["request" => "getInvoicePaymentTypes", "content" => [],] );

        if ( !empty( $result ) &&
            !empty( $result["success"] ) &&
            !empty( $result["response"] ) &&
            !empty( $result["response"]->status ) &&
            !empty( $result["response"]->status->responseStatus ) &&
            ( $result["response"]->status->responseStatus == "ok" )
        ) {
            $records = $result["response"]->records;

            foreach ( $records as $record ) {
                $paymentMethods[$record->id] = $record->type;
            }

            $this->order_payment_types = $paymentMethods;
            update_option( "orders_payment_types", wp_json_encode( $paymentMethods ) );
        }

        return $paymentMethods;
    }

    /**
     * Update Erply countries in local db
     *
     * @return array
     */
    public function update_erply_countries(){
        $countries_array = [];

        $p = 1;
        $current = 0;
        $total = 1;
        while ($current < $total) {
			$countries_request = $this->get_erply_countries( $p );
			$countries = $countries_request->records;
			foreach ( $countries as $country ) {
				$countries_array[$country->countryCode] = $country;
			}
			$current = $current + $countries_request->status->recordsInResponse;
			$total = $countries_request->status->recordsTotal;
			$p++;
		}


        if ( !empty( $countries_array ) ) {
            $local_countries = get_option( "erply_countries" );

            if ( !empty( $local_countries ) ) {
                $countries_array = array_merge( json_decode( $local_countries ), $countries_array );
            }

            update_option( "erply_countries", wp_json_encode($countries_array) );
            update_option( "last_updated_erply_countries", time() );

            $this->erply_countries = $countries_array;
        }


        return $countries_array;
    }

    /**
     * Get list of countries from Erply
     *
     * @param int $page
     * @return array|bool
     */
    public function get_erply_countries( $page = 1 ){

    	$body = [
            "request" => "getCountries",
			"recordsOnPage" => 100,
			"pageNo" => $page,
        ];
		$last_updated = get_option( "last_updated_erply_countries" );

		if ( !empty( $last_updated ) ) {
			$body["changedSince"] = $last_updated;
		}

        $result = $this->send_request_to_erply( $body );

        if ( !empty( $result["success"] ) &&
            !empty( $result["response"] ) &&
            !empty( $result["response"]->status ) &&
            !empty( $result["response"]->records &&
				(sizeof($result["response"]->records) > 0)
			)
        ) {
            return $result["response"];
        } else {
            return false;
        }
    }

    /**
     * Get Erply Country ID by Country Code
     *
     * @param $code
     * @return int (zero if country not found)
     */
    public function get_erply_country_id_by_code( $code ){
        if ( key_exists( $code, $this->erply_countries ) ) {
            return $this->erply_countries->$code->countryID;
        }

        return 0;
    }


    /**
     * Set sync status to failed, with date
     */
    public function set_status_sync_failed(){
        update_option( "woo_erply_sync_status", "Last sync failed at ".date("d.m.Y H:i:s").". Refer to logs for more info" );
    }

    /**
     * Get list of erply VAT rates. If $rate_id supplied get rate for this VAT instead the list
     *
     * @param string $rate_id
     * @return array|bool|string
     */
    public function get_erply_vat_rates( $rate_id = '' ){
        $parameters = [];
        $rates      = [];

        if ( !empty( $rate_id ) ) {
            $parameters["searchAttributeName"]  = "id";
            $parameters["id"]  = $rate_id;
            $parameters["searchAttributeValue"] = $rate_id;
        }

        $cached_vat_rates = get_option( 'erply_vat_rates' );
        if ($cached_vat_rates) {
            $cached_vat_rates = json_decode($cached_vat_rates);
            $ts = time();
            if (($ts - $cached_vat_rates->ts) < $this->cache_time ) {
                if ( !empty( $rate_id ) ) {
                    if (isset($cached_vat_rates->rates->$rate_id)) {
                        return $cached_vat_rates->rates->$rate_id;
                    }
                } else {
                    return $cached_vat_rates->rates;
                }
            }
        }

        $body = [
            "request" => "getVatRates",
            "content" => $parameters,
        ];

        $result = $this->send_request_to_erply( $body );

        if ( !empty( $result["success"] ) &&
            !empty( $result["response"] ) &&
            !empty( $result["response"]->status ) &&
            !empty( $result["response"]->records )
        ) {
            $records = $result["response"]->records;
            $return_single_rate = false;

            if ( !empty( $rate_id ) ) {
                foreach ( $records as $record ) {
                    if ( $record->id == $rate_id) {
                        $return_single_rate = true;
                        $rate_to_return = $record->rate;
                    }
                }
            }

            foreach ( $records as $record ) {
                if ( !empty( $record->active ) ) {
                    $rates[$record->id] = $record->name;
                }
            }
            $cached_vat_rates = [];
            $cached_vat_rates['ts'] = time();
            $cached_vat_rates['rates'] = $rates;

            update_option(  'erply_vat_rates', json_encode($cached_vat_rates),  'yes' );

            if ($return_single_rate) {
                return $rate_to_return;
            } else {
                return $rates;
            }

        }

        return false;
    }

    /**
     * Get list of Erply address types
     *
     * @return array|bool
     */
    public function get_erply_address_types(){
        $parameters    = [];
        $address_types = [];

        $cached_address_types = get_option( 'erply_address_types' );
        if ($cached_address_types) {
            $cached_address_types = json_decode($cached_address_types);
            $ts = time();
            if (($ts - $cached_address_types->ts) < $this->cache_time ) {
                return $cached_address_types->types;
            }
        }

        $body = [
            "request" => "getAddressTypes",
            "content" => $parameters,
        ];

        $result = $this->send_request_to_erply( $body );

        if ( !empty( $result["success"] ) &&
            !empty( $result["response"] ) &&
            !empty( $result["response"]->status ) &&
            !empty( $result["response"]->records )
        ) {
            $records = $result["response"]->records;

            foreach ( $records as $record ) {
                if ( !empty( $record->activelyUsed ) ) {
                    $address_types[$record->id] = $record->name;
                }
            }

            $cached_address_types = [];
            $cached_address_types['ts'] = time();
            $cached_address_types['types'] = $address_types;

            update_option(  'erply_address_types', json_encode($cached_address_types),  'yes' );

            return $address_types;
        }

        return false;
    }

    /**
     * Get Erply configuration parameters
     *
     * @return mixed
     */
    public function get_erply_conf_parameters(){

        $cached_conf_params = get_option( 'erply_conf_parameters' );
        if ($cached_conf_params) {
            $cached_conf_params = json_decode($cached_conf_params);
            $ts = time();
            if (($ts - $cached_conf_params->ts) < $this->cache_time ) {
                return $cached_conf_params->params;
            }
        }

        $json_body = [
            "request" => "getConfParameters",
            "content" => [],
        ];

        $result = $this->send_request_to_erply( $json_body );

        if ( !empty( $result["success"] ) &&
            !empty( $result["response"] ) &&
            !empty( $result["response"]->status ) &&
            !empty( $result["response"]->records )
        ) {
            $conf_params = $result["response"]->records[0];

            $cached_conf_params = [];
            $cached_conf_params['ts'] = time();
            $cached_conf_params['params'] = $conf_params;

            update_option(  'erply_conf_parameters', json_encode($cached_conf_params),  'yes' );

            return $conf_params;
        } else {
        	return false;
		}
    }

    /**
     * Add column to orders list in Woo admin
     *
     * @param $columns
     * @return array
     */
    public function erply_shop_order_column( $columns ) {
        $reordered_columns = [];

        // Inserting columns to a specific location
        foreach( $columns as $key => $column ) {
            $reordered_columns[$key] = $column;

            if( $key ==  'order_status' ){
                // Inserting after "Status" column
                $reordered_columns['erply_link'] = __( 'Erply Order ID','theme_domain');
            }
        }

        return $reordered_columns;
    }

    /**
     * Display content if available for orders columns
     *
     * @param $column
     * @param $post_id
     */
    public function erply_orders_list_column_content( $column, $post_id ) {
        if ( $column == "erply_link" ) {
            $link               = "";
            $erply_order_id     = get_post_meta( $post_id, "erply_order_id", true );
            $erply_invoice_link = get_post_meta( $post_id, "erply_invoice_link", true );

            if ( !empty( $erply_order_id ) && !empty( $erply_invoice_link ) ) {
                if ( preg_match( "/^https?\:\/\/[a-z\d]+\.erply\.com\/\d+\//i", $erply_invoice_link, $matches ) ) {
                    $link = '<a class="erply-link" href="' . $matches[0] . '?lang=eng&section=invoice&edit=' . $erply_order_id . '">' . $erply_order_id . '</a>';
                }
            }

            echo $link;
        }
    }

    /**
     * Check if erply woo sync logs download was initiated.
     * Download requested file
     */
    public function maybe_donwload_sync_logs(){
        if ( is_admin() && !empty( $_GET["page"] ) && $_GET["page"] == $this->settings_slug &&
            !empty( $_GET["tab"] ) && $_GET["tab"] == "woo_erply_logs" && !empty( $_GET["download_woo_erply_sync_logs"] )
        ) {
            Woo_Erply_Main::download_sync_logs( $_GET["download_woo_erply_sync_logs"] );
        }
    }
}
