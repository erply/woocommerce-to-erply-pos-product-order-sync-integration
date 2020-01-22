<?php

/**
 * Class Woo_Erply_Flow
 */
class Woo_Erply_Flow extends Woo_Erply_Main
{
	public $settings;
	public $vat_rate;
	public $erply_currencies = [];
	public $erply_matrix_attributes = [];
	public $custom_attributes = [];

	public function __construct($args)
	{
		parent::__construct($args);

		$this->settings = new Woo_Erply_Settings($args);

		add_action("woo_erply_sync", array($this, "run_woo_erply_sync"), 99);
		add_action("woo_erply_sync_attributes", array($this, "sync_attributes_bulk"), 99);
		add_action("woo_erply_sync_attributes_items", array($this, "sync_attributes_items_bulk"), 99);
		add_action("woo_erply_prepare_products", array($this, "sync_products_bulk"), 99);
		add_action("woo_erply_sync_products", array($this, "process_products_bulk_sync"), 99);
		add_action("woo_erply_maybe_archive_products", array($this, "maybe_archive_erply_products"), 99);
		add_action("update_erply_products_stock", array($this, "update_erply_stock"), 99);
		add_action("woo_erply_sync_coupons", array($this, "sync_coupons"), 99);
		add_action("woo_erply_sync_shipping_methods", array($this, "sync_shipping_methods"), 99);
		add_action("woo_erply_sync_orders", array($this, "sync_orders"), 99);
		add_action("woo_erply_sync_single_order", array($this, "sync_single_order_by_id"), 99);

		$this_event = wp_get_scheduled_event("woo_erply_sync_single_order");

		if (empty($this_event) || empty($this_event->timestamp)) {
			wp_schedule_event(time(), 10 * MINUTE_IN_SECONDS, "woo_erply_sync_single_order");;
			//wp_schedule_event( time(), 10, "woo_erply_sync_single_order" );;
		}

		if (!empty(get_option("sync_orders_immediately"))) {
			// add_action( 'wp_insert_post', array( $this, "immediate_order_sync" ), 99, 3 );
			add_action('woocommerce_payment_complete', array($this, "immediate_order_sync"), 99, 3);
			add_action('woocommerce_order_status_completed', array($this, "immediate_order_sync"), 99, 3);


		}
	}

	/**
	 * Perform synchronization between WooCommerce and Erply
	 *
	 * @return bool
	 */
	public function run_woo_erply_sync()
	{
		$rts = get_option("resource_to_sync");

		switch ($rts) {
			case 'products':
				$validate_products = $this->validate_products();
				if (empty($validate_products)) {
					return false;
				}

				wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_sync_attributes');

				break;
			case 'orders':
				$this->sync_coupons();

				$this->sync_shipping_methods();

				$this->sync_orders();

				break;
			case 'stocks':
				wp_schedule_single_event(MINUTE_IN_SECONDS, 'update_erply_products_stock');

				break;
		}

		return true;
	}

	/**
	 * Remove synchronization function from scheduled wp_cron actions
	 */
	public static function unschedule_synchronization()
	{
		$this_event = wp_get_scheduled_event("woo_erply_sync_products");

		if ($this_event && !empty($this_event->timestamp)) {
			wp_unschedule_event($this_event->timestamp, "woo_erply_sync_products");
		}

		$this_event = wp_get_scheduled_event("woo_erply_sync_orders");

		if ($this_event && !empty($this_event->timestamp)) {
			wp_unschedule_event($this_event->timestamp, "woo_erply_sync_orders");
		}
	}


	/**
	 *
	 * Get seconds to next full hour
	 * @return int
	 *
	 */

	public static function secondsToFullHour() {
		$date = new DateTime();
		$minutes = $date->format('i');
		if ($minutes > 0) {
			$date->modify("+1 hour");
			$date->modify('-'.$minutes.' minutes');
		}
		$ts = date_timestamp_get($date);
		return $ts;
	}

	/**
	 * Get all products that are available for synchronization
	 *
	 * @param array $args
	 * @return WP_Query
	 */
	public static function get_available_products($args = [])
	{

		$defaults = [
			'post_type' => 'product',
			'post_status' => 'publish',
			"posts_per_page" => -1,
			"suppress_filters" => 0
		];

		$args = array_merge($args, array_diff_key($defaults, $args));

		return new WP_Query($args);
	}

	/**
	 * Validate WooCommerce products
	 *
	 * @return bool
	 */
	public function validate_products()
	{
		$wp_query_products = self::get_available_products();
		$products = $wp_query_products->get_posts();

		foreach ($products as $product) {
			$wc_product = wc_get_product($product);
			$atrs = $wc_product->get_attributes();
			// Validate all products. Fail if any product has more than 3 attributes
			if (!empty($atrs) && count($atrs) > 3) {
				$error = __("Product", $this->textdomain) . ' <a href="' . get_edit_post_link($wc_product->get_id()) . '">' . $wc_product->get_id() . '</a> ' . __("has more than 3 attributes and can not be synchronized with Erply.", $this->textdomain);
				$log_data = date("Y-m-d H:i:s") . " - Products validation fail. Error occured: " . $error;
				Woo_Erply_Main::write_to_log_file($log_data);
				Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

				add_action('erply_notices', function () use ($error) {
					echo '<div class="updated error is-dismissible"><p>' . __("Error occured", $this->textdomain) . ': ' . $error . '</p></div>';
				});
				return false;
			}
		}

		$log_data = date("Y-m-d H:i:s") . " - Products validation success.";
		Woo_Erply_Main::write_to_log_file($log_data);
		Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

		return true;
	}

	/**
	 * Synchronize all product attribute taxonomies with bulk calls to Erply
	 *
	 * @return bool
	 */
	public function sync_attributes_bulk()
	{
		Woo_Erply_Main::write_to_log_file("Start synchronizing product attributes.");

		$attribute_taxonomies = array_chunk(wc_get_attribute_taxonomies(), 100);
		$attrs = [];
		$request = [];

		// Sync global attributes
		foreach ($attribute_taxonomies as $attributes) {

			foreach ($attributes as $attribute) {

				$attrs[$attribute->attribute_id] = $attribute->attribute_label;

				$erply_dimension_id = get_term_meta($attribute->attribute_id, "erply_dimension_id", true);

				if (empty($erply_dimension_id)) {
					$request[] = [
						"requestID" => $attribute->attribute_id,
						"requestName" => "saveMatrixDimension",
						"name" => $attribute->attribute_label,
					];
				} else {
					Woo_Erply_Main::write_to_log_file("Attribute ".$attribute->attribute_label." with ID ".$erply_dimension_id." already synced to Erply.");
					continue;
				}

			}

			if (!empty($request)) {
				$result = $this->settings->send_request_to_erply(["requests" => $request]);

				if ($result == 429) {
					wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_sync_attributes');
					return true;
				}
				if ($result == 1002) {
					wp_schedule_single_event($this->secondsToFullHour(), 'woo_erply_sync_attributes');
					return true;
				}


				if (!empty($result["success"]) &&
					!empty($result["response"]) &&
					!empty($result["response"]->requests)) {

					foreach ($result["response"]->requests as $result_request) {
						if (!empty($result_request->status) &&
							$result_request->status->responseStatus == "ok" &&
							!empty($result_request->records) &&
							!empty($result_request->records[0]->dimensionID)
						) {
							update_term_meta($result_request->status->requestID, "erply_dimension_id", $result_request->records[0]->dimensionID);
							Woo_Erply_Main::write_to_log_file("Product attribute id:" . $result_request->status->requestID . " - '" . $attrs[$result_request->status->requestID] . "' synchronized and received dimensionID " . $result_request->records[0]->dimensionID);
						} else {
							$sync_error = true;
							Woo_Erply_Main::write_to_log_file("Product attribute id:" . $result_request->status->requestID . " - '" . $attrs[$result_request->status->requestID] . "' FAILED to synchronize with error code: " . $result_request->status->errorCode);
						}
					}

					if (!empty($sync_error)) {
						$this->settings->set_status_sync_failed();

						return false;
					}
				} else {
					$this->settings->set_status_sync_failed();

					return false;
				}
			} else {
				Woo_Erply_Main::write_to_log_file("No attributes found for synchronizing. Either all attributes where synced before or there is no attributes at all.");
			}

		}

		Woo_Erply_Main::write_to_log_file("Finished synchronizing product attributes.");
		Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

		wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_sync_attributes_items');

		return true;
	}

	/**
	 * Synchronize all product attribute items with bulk calls to Erply
	 *
	 * @return bool
	 */
	public function sync_attributes_items_bulk()
	{
		Woo_Erply_Main::write_to_log_file("Start synchronizing product attribute items.");

		$x = 0; // Bulk request index
		$y = 1; // Inner request index. Starts with one because Erply likes attributeId1 and such
		$bulkRequests = [];
		$attrs = [];
		$attributes = wc_get_attribute_taxonomies();
		Woo_Erply_Main::write_to_log_file("Total number of attributes: " . sizeof($attributes));
		// Loop through each product attribute, collect possible values and form up data for bulk requests
		foreach ($attributes as $attribute) {
			$erply_dimension_id = get_term_meta($attribute->attribute_id, "erply_dimension_id", true);
			Woo_Erply_Main::write_to_log_file("Synchronizing product attribute with Erply ID " . $erply_dimension_id);
			if (!empty($erply_dimension_id)) {
				$attribute_terms = get_terms(['taxonomy' => 'pa_' . $attribute->attribute_name]);

				foreach ($attribute_terms as $term) {

					Woo_Erply_Main::write_to_log_file("Synchronizing product attribute term: " . $term->name . "(" . $term->term_id . ")");
					$attrs[$term->term_id] = $term->name;

					$erply_dimension_item_id = get_term_meta($term->term_id, "erply_dimension_item_id", true);

					if (empty($erply_dimension_item_id)) {
						$bulkRequests[$x][$y] = [
							"requestID" => $term->term_id,
							"dimensionID" => $erply_dimension_id,
							"name" => $term->name,
							"code" => $term->term_id,
							"requestName" => "addItemToMatrixDimension",
						];
					} else {
						$bulkRequests[$x][$y] = [
							"requestID" => $term->term_id,
							"itemID" => $erply_dimension_item_id,
							"name" => $term->name,
							"code" => $term->term_id,
							"requestName" => "editItemInMatrixDimension",
						];
					}

					if ($y == 100) {
						$x++;
						$y = 1;
					} else {
						$y++;
					}

				}
			}

		}

		// Send each data group as bulk request to Erply
		foreach ($bulkRequests as $request) {
			$result = $this->settings->send_request_to_erply(["requests" => $request]);


			if ($result == 429) {
				wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_sync_attributes_items');
				return true;
			}
			if ($result == 1002) {
				wp_schedule_single_event($this->secondsToFullHour(), 'woo_erply_sync_attributes_items');
				return true;
			}

			if (!empty($result["success"]) &&
				!empty($result["response"]) &&
				!empty($result["response"]->requests)
			) {

				foreach ($result["response"]->requests as $result_request) {

					if (!empty($result_request->status) &&
						$result_request->status->responseStatus == "ok" &&
						!empty($result_request->records) &&
						!empty($result_request->records[0]->itemID)
					) {
						update_term_meta($result_request->status->requestID, "erply_dimension_item_id", $result_request->records[0]->itemID);

						Woo_Erply_Main::write_to_log_file("Product attribute term id:" . $result_request->status->requestID . " - '" . $attrs[$result_request->status->requestID] . "' synchronized and received itemID " . $result_request->records[0]->itemID);
					} else {
						$sync_error = true;
						Woo_Erply_Main::write_to_log_file("Product attribute term id:" . $result_request->status->requestID . " - '" . $attrs[$result_request->status->requestID] . "' FAILED to synchronize with error code: " . $result_request->status->errorCode);
						if ($result_request->status->errorCode == 1011) {
							if ($result_request->status->errorField == "itemID") {
								Woo_Erply_Main::write_to_log_file("Possible reason: matrix dimension not available at Erply");
							}
						}
					}

				}

				if (!empty($sync_error)) {
					$this->settings->set_status_sync_failed();

					return false;
				}

			} else {
				$this->settings->set_status_sync_failed();

				return false;
			}
		}

		Woo_Erply_Main::write_to_log_file("Finished synchronizing product attribute items.");
		Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

		update_option("products_bulk_sync_type", "products");
		wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_prepare_products');

		return true;
	}


	/**
	 * Syncs custom matrix dimension attributes to Erply.
	 * Return the ID of the custom attribute
	 * Uses the existing attribute or variation, if matches by name.
	 *
	 * @return bool|void
	 */
	public function sync_custom_attribute($attr, $term = '')
	{
		$attr = str_replace("pa_", "", $attr);
		$attr = str_replace("attribute_", "", $attr);

		if (empty($this->custom_attributes)) {
			$request = [
				"request" => "getMatrixDimensions",
				"pageSize" => 100,
			];
			$result = $this->settings->send_request_to_erply($request);

			if ($result == 429) {
				wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_sync_products');
				return true;
			}
			if ($result == 1002) {
				wp_schedule_single_event($this->secondsToFullHour(), 'woo_erply_sync_products');
				return true;
			}

			$dimensions = [];

			if (!empty($result["success"]) &&
				!empty($result["response"]) &&
				!empty($result["response"]->records) &&
				$result["response"]->status->responseStatus == "ok"
			) {
				foreach ($result["response"]->records as $dim) {
					$dimensions[$dim->name]["erplyDimensionId"] = $dim->dimensionID;
					$variations = [];

					foreach ($dim->variations as $var) {
						$variations[$var->name]["erplyItemID"] = $var->variationID;
					}
					$dimensions[$dim->name]["variations"] = $variations;
				}
			}

			$this->custom_attributes = $dimensions;
		}


		if (array_key_exists($attr, $this->custom_attributes)) {
			if ($term == '') {
				if (!empty($this->custom_attributes[$attr]["erplyDimensionId"])) {
					return $this->custom_attributes[$attr]["erplyDimensionId"];
				}
			}
		} else {
			$request = [
				"request" => "saveMatrixDimension",
				"name" => $attr
			];

			$response = $this->settings->send_request_to_erply($request);

			if (!empty($response["response"]) && $response["response"]->status == "success" && $response["response"]->status->responseStatus == "ok") {
				$erplyDimId = $response["response"]->records[0]->dimensionID;
				$this->custom_attributes[$attr]["erplyDimensionId"] = $erplyDimId;
				if ($term == '') {
					return $erplyDimId;
				}
			}
		}


		if (!empty($this->custom_attributes[$attr])) {
			$erplyDimId = $this->custom_attributes[$attr]["erplyDimensionId"];
			if (array_key_exists($term, $this->custom_attributes[$attr]["variations"])) {
				$erplyItemId = $this->custom_attributes[$attr]["variations"][$term]["erplyItemID"];
				if (!empty($erplyItemId)) {
					return $erplyItemId;
				}
			} else {
				$request = [
					"request" => "addItemToMatrixDimension",
					"name" => $term,
					"dimensionID" => $erplyDimId,
				];
				$response = $this->settings->send_request_to_erply($request);
				if ($response["success"] && !empty($response["response"]) && $response["response"]->status == "success" && $response["response"]->status->responseStatus == "ok") {
					$erplyItemId = $response["response"]->records[0]->itemID;
					$this->custom_attributes[$attr]["variations"][$term]["erplyItemID"] = $erplyItemId;
					return $erplyItemId;
				}
			}
		}

	}


	/**
	 * Generates slugs from names
	 *
	 * @return bool|void
	 */
	public function slugify($text)
	{
		$text = str_replace("ä", "a", $text);
		$text = str_replace("ö", "o", $text);
		$text = str_replace("õ", "o", $text);
		$text = str_replace("ü", "u", $text);

		$text = preg_replace('~[^\pL\d]+~u', '-', $text);

		$text = iconv('utf-8', 'ISO-8859-1//TRANSLIT', $text);

		$text = preg_replace('~[^-\w]+~', '', $text);

		$text = trim($text, '-');

		$text = preg_replace('~-+~', '-', $text);

		$text = strtolower($text);
		if (empty($text)) {
			return 'n-a';
		}
		return $text;
	}

	/**
	 * Prepare requests for woo-erply products sync and initiate products sync process
	 *
	 * @return bool|void
	 */
	public function sync_products_bulk()
	{

		$sync_prod_type = get_option("products_bulk_sync_type");

		$sync_prod_group = get_option("woo_erply_sync_products_group");
		$args = [
			"posts_per_page" => -1,
			"orderby" => "ID",
			"order" => "ASC",
		];

		$erply_query = self::get_available_products($args);
		$products = $erply_query->get_posts();
		$requests = [];
		$x = 0;
		$y = 1;

		foreach ($products as $product) {
			$wc_product = wc_get_product($product);
			$erply_product_id = get_post_meta($wc_product->get_id(), "erply_product_id", true);

			if ($wc_product->is_type('variable')) {
				$var_attr = $wc_product->get_variation_attributes();
				$variations = $wc_product->get_available_variations();
				$attributes = wc_get_attribute_taxonomies();

				if ($sync_prod_type == "products") {

					$requests[$x][$y] = [
						"requestID" => $wc_product->get_id(),
						"type" => "MATRIX",
						"groupID" => $sync_prod_group,
						"code" => $wc_product->get_id(),
						"name" => $wc_product->get_name(),
						"displayedInWebshop" => 1,
						"priceWithVAT" => $wc_product->get_price(),
						"longdesc" => $wc_product->get_description(),
						"description" => $wc_product->get_short_description(),
						"requestName" => "saveProduct",
					];

					if (!empty($erply_product_id)) {
						$requests[$x][$y]["productID"] = $erply_product_id;
					}

					$i = 1;
					foreach ($attributes as $attribute) {
						if (!empty($var_attr["pa_" . $attribute->attribute_name])) {
							$requests[$x][$y]["dimensionID" . $i] = get_term_meta($attribute->attribute_id, "erply_dimension_id", true);
							unset($var_attr["pa_" . $attribute->attribute_name]);
							$i++;
						}

					}
					if (sizeof($var_attr) > 0) {
						Woo_Erply_Main::write_to_log_file("Warning! Product has custom (product based) attributes. Syncing those attributes. ");
						foreach ($var_attr as $k => $v) {
							$k = $this->slugify($k);
							$dimId = $this->sync_custom_attribute($k);
							if (!empty($dimId)) {
								$requests[$x][$y]["dimensionID" . $i] = $dimId;
								unset($var_attr["pa_" . $k]);
							} else {
								Woo_Erply_Main::write_to_log_file("Unexpected error during custom attribute sync.");
							}
						}
					}

					if ($y == 100) {
						$x++;
						$y = 1;
					} else {
						$y++;
					}
				} else if ($sync_prod_type == "variations") {

					foreach ($variations as $key => $value) {

						$erply_product_id_variation = get_post_meta($value["variation_id"], "erply_product_id", true);

						$name = $wc_product->get_name();

						$requests[$x][$y] = [
							"requestID" => $value["variation_id"],
							"type" => "PRODUCT",
							"groupID" => $sync_prod_group,
							"code" => $value["variation_id"],
							"displayedInWebshop" => 1,
							"priceWithVAT" => (!empty($value["display_regular_price"])) ? $value["display_regular_price"] : $wc_product->get_price(),
							"longdesc" => (!empty($value["variation_description"])) ? $value["variation_description"] : $wc_product->get_description(),
							"description" => $wc_product->get_short_description(),
							"parentProductID" => $erply_product_id,
							"requestName" => "saveProduct",
						];

						if (!empty($erply_product_id_variation)) {
							$requests[$x][$y]["productID"] = $erply_product_id_variation;
						}

						if (!empty($value["attributes"])) {
							$name .= " - ";
							$i = 1;
							foreach ($value["attributes"] as $k => $v) {
								$has_term = false;
								if ($v != '') {
									$terms = get_terms(['taxonomy' => str_replace("attribute_", "", $k)]);

									foreach ($terms as $term) {
										if ($v == $term->slug) {
											$name .= $term->name . " ";
											$dimId = get_term_meta($term->term_id, "erply_dimension_item_id", true);
											$requests[$x][$y]["dimValueID" . $i] = $dimId;
											$has_term = true;
											break;
										}

									}
								} else {
									$v = "Any";
									$k = str_replace("attribute_", "", $k);
									$dimId = $this->sync_custom_attribute($k, $v);
									$name .= $v . " ";
									$requests[$x][$y]["dimValueID" . $i] = $dimId;
									$has_term = true;
								}

								if (!$has_term) {
									$k = str_replace("attribute_", "", $k);
									$dimId = $this->sync_custom_attribute($k, $v);
									$name .= $v . " ";
									$requests[$x][$y]["dimValueID" . $i] = $dimId;
								}
								$i++;
							}
						}
						if (substr($name, -1) == " ") {
							$name = substr_replace($name, "", -1);
						}
						if (substr($name, -1) == "-") {
							$name = substr_replace($name, "", -1);
						}
						if (substr($name, -1) == " ") {
							$name = substr_replace($name, "", -1);
						}
						$requests[$x][$y]["name"] = $name;

						if ($y == 100) {
							$x++;
							$y = 1;
						} else {
							$y++;
						}
					}
				}

			}
			else if (($sync_prod_type == "products") && (!$wc_product->is_type('variable'))) {
				$requests[$x][$y] = [
					"requestID" => $wc_product->get_id(),
					"type" => "PRODUCT",
					"groupID" => $sync_prod_group,
					"code" => $wc_product->get_id(),
					"name" => $wc_product->get_name(),
					"displayedInWebshop" => 1,
					"priceWithVAT" => $wc_product->get_price(),
					"longdesc" => $wc_product->get_description(),
					"description" => $wc_product->get_short_description(),
					"requestName" => "saveProduct",
				];

				if (!empty($erply_product_id)) {
					$requests[$x][$y]["productID"] = $erply_product_id;
				}

				if ($y == 100) {
					$x++;
					$y = 1;
				} else {
					$y++;
				}

			} else {
				continue;
			}

		}

		if (!empty($requests)) {
			update_option("products_bulk_sync_requests", wp_json_encode($requests));
		}

		wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_sync_products');

		add_action('erply_notices', function () {
			echo '<div class="updated notice-info is-dismissible"><p>Synchronization is in progress. It might take a while - depends on amount of products and variations in woocommerce</p></div>';
		});

	}

	/**
	 * Send products sync requests to Erply.
	 * Initiate products stock sync on finish.
	 *
	 * @return bool
	 */
	public function process_products_bulk_sync()
	{
		$json = get_option("products_bulk_sync_requests", "");

		if (!empty($json)) {
			$requests = json_decode($json);

			if (!empty($requests)) {
				foreach ($requests as $key => $request) {
					$result = $this->settings->send_request_to_erply(["requests" => $request]);

					if ($result == 429) {
						wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_sync_products');
						return true;
					}
					if ($result == 1002) {
						wp_schedule_single_event($this->secondsToFullHour(), 'woo_erply_sync_products');
						return true;
					}

					if (!empty($result["success"]) &&
						!empty($result["response"]) &&
						!empty($result["response"]->requests)
					) {
						foreach ($result["response"]->requests as $result_request) {
							if (!empty($result_request->status) &&
								$result_request->status->responseStatus == "ok" &&
								!empty($result_request->records) &&
								!empty($result_request->records[0]->productID)
							) {
								update_post_meta($result_request->status->requestID, "erply_product_id", $result_request->records[0]->productID);
								Woo_Erply_Main::write_to_log_file("Product " . $result_request->status->requestID . " synchronized and got erply_product_id " . $result_request->records[0]->productID);
							} else {
								$sync_error = true;
								$error = '';
								if (isset($result_request->status->errorField)) {
									$error = $result_request->status->errorField;
								}
								Woo_Erply_Main::write_to_log_file("Product " . $result_request->status->requestID . "' FAILED to synchronize with error code: " . $result_request->status->errorCode . " (" . $error) . ")";
							}
						}

						if (!empty($sync_error)) {
							$this->settings->set_status_sync_failed();

							self::unschedule_synchronization();

							Woo_Erply_Main::write_to_log_file(date("Y-m-d H:i:s") . " - Failed Products Synchronization.");
							Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

							return false;
						}

						unset($requests[$key]);

					} else {
						$this->settings->set_status_sync_failed();

						self::unschedule_synchronization();

						Woo_Erply_Main::write_to_log_file(date("Y-m-d H:i:s") . " - Failed Products Synchronization.");
						Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

						return false;
					}

				} // foreach $requests

				if (!empty($requests)) {
					update_option("products_bulk_sync_requests", wp_json_encode($requests));

					self::unschedule_synchronization();

					wp_schedule_single_event(61 * MINUTE_IN_SECONDS, 'woo_erply_sync_products');

					return true;
				}
			}
		}


		$sync_prod_type = get_option("products_bulk_sync_type");
		if ($sync_prod_type == "products") {
			update_option("products_bulk_sync_type", "variations");
			wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_prepare_products');
		} else {
			update_option("products_bulk_sync_requests", "");

			$date = date("Y-m-d H:i:s");

			Woo_Erply_Main::write_to_log_file($date . " - Finished Products Synchronization.");
			Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

			self::unschedule_synchronization();

			delete_option("products_bulk_sync_type");
			wp_schedule_single_event(MINUTE_IN_SECONDS, 'update_erply_products_stock');

		}

	}


	/**
	 * Return the product stock numbers from Erply
	 *
	 * @return array|bool
	 */
	public function get_erply_product_stock()
	{
		$request = [];
		$request = [
			"warehouseID" => get_option("woo_erply_sync_warehouse"),
			"request" => "getProductStock",
		];

		$products_and_stocks = [];

		$erply_stocks = $this->settings->send_request_to_erply( $request );

		if (!empty($erply_stocks) &&
			!empty($erply_stocks["success"]) &&
			!empty($erply_stocks["response"]) &&
			!empty($erply_stocks["response"]->status) &&
			!empty($erply_stocks["response"]->status->responseStatus) &&
			($erply_stocks["response"]->status->responseStatus == "ok")
		) {
			$erply_products_and_stocks = $erply_stocks["response"]->records;
		} else {
			$this->settings->set_status_sync_failed();
			return false;
		}

		if (empty($erply_products_and_stocks)) {
			$this->settings->set_status_sync_failed();

			return false;
		}

		foreach ($erply_products_and_stocks as $product) {
			$products_and_stocks[$product->productID] = $product->amountInStock;
		}

		return $products_and_stocks;
	}

	/**
	 * Update stock for product.
	 * Use when it is added to erply for the first time.
	 *
	 * @return bool
	 */
	public function update_erply_stock()
	{
		Woo_Erply_Main::write_to_log_file(date("Y-m-d H:i:s") . " - Start Products Stock Synchronization.");

		$args = [
			"posts_per_page" => -1,
			"orderby" => "ID",
			"order" => "ASC",
		];

		$erply_stocks = $this->get_erply_product_stock();

		$erply_query = self::get_available_products($args);
		$products = $erply_query->get_posts();
		$request[0] = [
			"warehouseID" => get_option("woo_erply_sync_warehouse"),
			"requestName" => "saveInventoryRegistration",
		];

		$i = 1;
		foreach ($products as $product) {
			$wc_product = wc_get_product($product);
			$erply_product_id = get_post_meta($wc_product->get_id(), "erply_product_id", true);

			if (empty($erply_product_id)) {
				Woo_Erply_Main::write_to_log_file(date("Y-m-d H:i:s") . " - Faild to update stock. Product " . $wc_product->get_id() . " is NOT Synchronized.");
				Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

				$this->settings->set_status_sync_failed();

				return false;
			}

			$erply_stock = null;
			$stock = $wc_product->get_stock_quantity();
			if (isset($erply_stocks[$erply_product_id])) {
				$erply_stock = $erply_stocks[$erply_product_id];
			}

			if ((!empty($erply_stock)) && ($stock == 0)) {
				$request[0]["productID" . $i] = $erply_product_id;
				$request[0]["amount" . $i] = (0 - $erply_stock);
				$request[0]["price" . $i] = $wc_product->get_price();
				$i++;
			} else if ((empty($erply_stock)) && ($stock != 0)) {
				$request[0]["productID" . $i] = $erply_product_id;
				$request[0]["amount" . $i] = $stock;
				$request[0]["price" . $i] = $wc_product->get_price();
				$i++;
			} else if ((!empty($erply_stock)) && ($stock != 0)) {
				if ($erply_stock != $stock) {
					$request[0]["productID" . $i] = $erply_product_id;
					$request[0]["amount" . $i] = (- ($erply_stock - $stock));
					$request[0]["price" . $i] = $wc_product->get_price();
					$i++;
				}
			}




			if ($wc_product->is_type('variable')) {
				$variations = $wc_product->get_available_variations();

				foreach ($variations as $key => $value) {

					$erply_product_id_variation = get_post_meta($value["variation_id"], "erply_product_id", true);


					if (empty($erply_product_id_variation)) {
						Woo_Erply_Main::write_to_log_file(date("Y-m-d H:i:s") . " - Faild to update stock. Product Variation " . $value["variation_id"] . " of Product " . $wc_product->get_id() . " is NOT Synchronized.");
						Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

						$this->settings->set_status_sync_failed();

						return false;
					}

					$variation_obj = new WC_Product_variation($value["variation_id"]);

					$erply_stock = null;
					$stock = $variation_obj->get_stock_quantity();
					if (isset($erply_stocks[$erply_product_id_variation])) {
						$erply_stock = $erply_stocks[$erply_product_id_variation];
					}


					if ((!empty($erply_stock)) && ($stock == 0)) {
						$request[0]["productID" . $i] = $erply_product_id_variation;
						$request[0]["amount" . $i] = (0 - $erply_stock);
						$request[0]["price" . $i] = $variation_obj->get_price();
						$i++;
					} else if ((empty($erply_stock)) && ($stock != 0)) {
						$request[0]["productID" . $i] = $erply_product_id_variation;
						$request[0]["amount" . $i] = $stock;
						$request[0]["price" . $i] = $variation_obj->get_price();
						$i++;
					} else if ((!empty($erply_stock)) && ($stock != 0)) {
						if ($erply_stock != $stock) {
							$request[0]["productID" . $i] = $erply_product_id_variation;
							$request[0]["amount" . $i] = -($erply_stock - $stock);
							$request[0]["price" . $i] = $variation_obj->get_price();
							$i++;
						}
					}

				}

			}

		} // foreach $products

		if ($i > 1) {
			$result = $this->settings->send_request_to_erply(["requests" => $request]);

			if ($result == 429) {
				wp_schedule_single_event(MINUTE_IN_SECONDS, 'update_erply_products_stock');
				return true;
			}
			if ($result == 1002) {
				wp_schedule_single_event($this->secondsToFullHour(), 'update_erply_products_stock');
				return true;
			}

			if (!empty($result["success"]) &&
				!empty($result["response"]) &&
				!empty($result["response"]->requests)
			) {
				foreach ($result["response"]->requests as $result_request) {
					if (empty($result_request->status) ||
						$result_request->status->responseStatus != "ok" ||
						empty($result_request->records)
					) {
						self::unschedule_synchronization();

						$this->settings->set_status_sync_failed();

						return false;
					}

					foreach ($result_request->records as $record) {
						if (empty($record->inventoryRegistrationID)) {
							self::unschedule_synchronization();

							$this->settings->set_status_sync_failed();

							return false;
						}
					}
				}

			} else {
				self::unschedule_synchronization();

				$this->settings->set_status_sync_failed();

				return false;
			}

		}

		self::unschedule_synchronization();

		Woo_Erply_Main::write_to_log_file(date("Y-m-d H:i:s") . " - Finished Products Stock Synchronization.");
		Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

		wp_schedule_single_event(5 * MINUTE_IN_SECONDS, 'woo_erply_maybe_archive_products');

		return true;
	}

	/**
	 * Check if there are products in Erply that are not present in Woo. Set their status to ARCHIVED in Erply.
	 *
	 * @return bool
	 */
	public function maybe_archive_erply_products()
	{
		$spg = get_option("woo_erply_sync_products_group");
		$warehuse_id = get_option("woo_erply_sync_warehouse", 0);
		$requests[] = [
			"warehouseID" => $warehuse_id,
			"requestName" => "getProducts",
		];
		$erply_ids = [];
		$woo_ids = [];
		// Get all erply products and store their IDs
		$result = $this->settings->send_request_to_erply(["requests" => $requests]);

		if ($result == 429) {
			wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_maybe_archive_products');
			return true;
		}
		if ($result == 1002) {
			wp_schedule_single_event($this->secondsToFullHour(), 'woo_erply_maybe_archive_products');
			return true;
		}

		if (!empty($result["success"]) &&
			!empty($result["response"]) &&
			!empty($result["response"]->requests)
		) {
			foreach ($result["response"]->requests as $result_request) {
				if (!empty($result_request->status) &&
					$result_request->status->responseStatus == "ok" &&
					!empty($result_request->records)
				) {
					$records = $result["response"]->records;

					foreach ($records as $record) {
						$erply_ids[] = $record->productID;
					}
				} else {
					self::unschedule_synchronization();

					$this->settings->set_status_sync_failed();

					return false;
				}
			}

		} else {
			self::unschedule_synchronization();

			$this->settings->set_status_sync_failed();

			return false;
		}

		// Get local woo products and store their erply_ids if they have it
		$erply_query = self::get_available_products();
		$products = $erply_query->get_posts();

		foreach ($products as $product) {
			$wc_product = wc_get_product($product);
			$erply_product_id = get_post_meta($wc_product->get_id(), "erply_product_id", true);

			if (!empty($erply_product_id)) {
				$woo_ids[] = $erply_product_id;
			}

			if ($wc_product->is_type('variable')) {
				$variations = $wc_product->get_available_variations();

				foreach ($variations as $key => $value) {
					$erply_variation_id = get_post_meta($value["variation_id"], "erply_variation_id", true);

					if (!empty($erply_variation_id)) {
						$woo_ids[] = $erply_variation_id;
					}
				}
			}
		}
		// Get erply products Ids that are not present in woo and set each product status to "ARCHIVED"
		$to_archive = array_diff($erply_ids, $woo_ids);
		$requests = [];
		$x = 0;
		$y = 1;

		foreach ($to_archive as $productID) {
			$requests[$x][$y] = [
				"requestID" => $productID,
				"status" => "ARCHIVED",
				"groupID" => $spg,
				"productID" => $productID,
				"requestName" => "saveProduct",
			];

			if ($y == 100) {
				$x++;
				$y = 1;
			} else {
				$y++;
			}
		}

		foreach ($requests as $request) {
			$result = $this->settings->send_request_to_erply(["requests" => $request]);

			if ($result == 429) {
				wp_schedule_single_event(MINUTE_IN_SECONDS, 'woo_erply_maybe_archive_products');
				return true;
			}
			if ($result == 1002) {
				wp_schedule_single_event($this->secondsToFullHour(), 'woo_erply_maybe_archive_products');
				return true;
			}

			if (!empty($result["success"]) &&
				!empty($result["response"]) &&
				!empty($result["response"]->requests)
			) {
				foreach ($result["response"]->requests as $result_request) {
					if (empty($result_request->status) ||
						$result_request->status->responseStatus != "ok" ||
						empty($result_request->records)
					) {
						self::unschedule_synchronization();

						$this->settings->set_status_sync_failed();

						return false;
					}
				}

			} else {
				self::unschedule_synchronization();

				$this->settings->set_status_sync_failed();

				return false;
			}

		}

		update_option("woo_erply_sync_status", "Last sync completed at " . date("Y-m-d H:i:s"));

		return true;
	}

	/**
	 * Synchronize woo shipping methods with Erply as delivery types
	 *
	 * @return bool
	 */
	public function sync_shipping_methods()
	{
		$erply_shipping_methods_codes = [];
		$delivery_types = $this->settings->get_delivery_types();

		if (!empty($delivery_types) &&
			!empty($delivery_types["success"]) &&
			!empty($delivery_types["response"]) &&
			!empty($delivery_types["response"]->status) &&
			!empty($delivery_types["response"]->status->responseStatus) &&
			($delivery_types["response"]->status->responseStatus == "ok")
		) {
			foreach ($delivery_types["response"]->records as $record) {
				$erply_shipping_methods_codes[] = $record->code;
			}
		} else {
			$this->settings->set_status_sync_failed();

			return false;
		}

		$shiping_methods = WC()->shipping->get_shipping_methods();
		$c = 0;

		foreach ($shiping_methods as $method) {
			if (!in_array($method->id, $erply_shipping_methods_codes)) {
				$sync_method = $this->settings->save_delivery_type($method->id, $method->method_title);
				$c++;
				if (empty($sync_method) ||
					empty($sync_method["success"]) ||
					empty($sync_method["response"]) ||
					empty($sync_method["response"]->status) ||
					empty($sync_method["response"]->status->responseStatus) ||
					($sync_method["response"]->status->responseStatus != "ok")
				) {
					$this->settings->set_status_sync_failed();

					return false;
				} else {
					Woo_Erply_Main::write_to_log_file("Shipping method '" . $method->method_title . "' saved in Erply as delivery type with ID=" . $delivery_types["response"]->records[0]->deliveryTypeID);
				}

			}
		}
		if ($c == 0) {
			Woo_Erply_Main::write_to_log_file("No shipping methods to sync or they are already synced.");
		}

		Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

		return true;
	}

	/**
	 * Return list of currencies from Erply
	 *
	 * @return array|bool
	 */
	public function get_erply_currencies_list()
	{
		$erply_currencies = $this->settings->get_erply_currencies();
		$api_currencies = [];
		$currency_codes = [];

		if (!empty($erply_currencies) &&
			!empty($erply_currencies["success"]) &&
			!empty($erply_currencies["response"]) &&
			!empty($erply_currencies["response"]->status) &&
			!empty($erply_currencies["response"]->status->responseStatus) &&
			($erply_currencies["response"]->status->responseStatus == "ok")
		) {
			$api_currencies = $erply_currencies["response"]->records;
		} else {
			$this->settings->set_status_sync_failed();

			return false;
		}

		if (empty($api_currencies)) {
			$this->settings->set_status_sync_failed();

			return false;
		}

		foreach ($api_currencies as $currency) {
			$currency_codes[$currency->code] = $currency;
		}

		return $currency_codes;
	}

	/**
	 * Perform Woo orders synchronization
	 *
	 * @return bool
	 */
	public function sync_orders()
	{

		$this->settings->update_erply_countries();
		$this->vat_rate = $this->settings->get_erply_vat_rates(get_option("default_vat_rate_id"));
		$paged = get_option("woo_erply_sync_order_page", 1);
		//$paged = 1;

		$this->erply_currencies = $this->get_erply_currencies_list();

		if (empty($this->erply_currencies)) {
			$this->settings->set_status_sync_failed();

			add_action('erply_notices', function () {
				echo '<div class="updated error is-dismissible"><p>Failed to fetch Erply currencies.</p></div>';
			});

			Woo_Erply_Main::write_to_log_file("Failed to fetch Erply currencies.");
			Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

			return false;
		}

		$wc_orders = wc_get_orders([
			'paged' => $paged,
			'numberposts' => 20,
			'orderby' => 'ID',
			'order' => 'ASC',
			'status' => 'completed',
			'type' => 'shop_order', // needs handling for refunds
			'paginate' => true,
		]);


		$synced = 1;
		foreach ($wc_orders->orders as $order) {

			$synced = $this->sync_single_order($order);

		}

		if (empty($synced)) {
			return false;
		}
		Woo_Erply_Main::write_to_log_file("Orders page  " . $paged);

		if ($synced == 1) {
			$paged++;
			update_option("woo_erply_sync_order_page", $paged);
		}


		if ($paged <= $wc_orders->max_num_pages) {

			self::unschedule_synchronization();

			wp_schedule_single_event(5 * MINUTE_IN_SECONDS, 'woo_erply_sync_orders');
		} else {
			$date = date("Y-m-d H:i:s");

			Woo_Erply_Main::write_to_log_file($date . " - Finished Synchronization.");
			Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

			update_option("woo_erply_sync_status", "Last sync completed at " . $date);
			update_option("woo_erply_sync_order_page", 1);
		}

		add_action('erply_notices', function () {
			echo '<div class="updated notice-info is-dismissible"><p>Synchronization is in progress. It might take a while - depends on amount of orders in woocommerce</p></div>';
		});
	}

	/**
	 * Synchronize one single order
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public function sync_single_order($order)
	{

		if (empty($this->vat_rate)) {
			$this->vat_rate = $this->settings->get_erply_vat_rates(get_option("default_vat_rate_id"));
		}

		$currency = $this->checkCurrency($order);
		if (!$currency) {
			$this->settings->set_status_sync_failed();
			return false;
		}

		$order_id = $order->get_id();
		$erply_order_id = get_post_meta($order_id, "erply_order_id", true);
		if (!empty($erply_order_id)) {
			return true;
		}
		Woo_Erply_Main::write_to_log_file("Syncing order " . $order_id);

		// Get a list of synced shipping methods from erply
		$erply_shipping_methods = [];
		$delivery_types = $this->settings->get_delivery_types();

		if (!empty($delivery_types) &&
			!empty($delivery_types["success"]) &&
			!empty($delivery_types["response"]) &&
			!empty($delivery_types["response"]->status) &&
			!empty($delivery_types["response"]->status->responseStatus) &&
			($delivery_types["response"]->status->responseStatus == "ok")
		) {
			foreach ($delivery_types["response"]->records as $record) {
				$erply_shipping_methods[$record->code] = $record->deliveryTypeID;
			}
		} else {
			$this->settings->set_status_sync_failed();

			Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

			return false;
		}
		// Get shipping method from the order
		$osm = $order->get_items('shipping');
		$osm_id = '';
		if (!empty($osm)) {
			$osm_id = reset($order->get_items('shipping'))->get_method_id();
		}
		// Set up delivery type ID to use for current order sync
		$deliveryTypeID = (!empty($osm_id) && key_exists($osm_id, $erply_shipping_methods)) ? $erply_shipping_methods[$osm_id] : 1;

		$odc = $order->get_date_created();

		$parameters = [
			"type" => get_option("synchronize_orders_as"),
			"warehouseID" => get_option("woo_erply_sync_warehouse"),
			"date" => $odc->date("Y-m-d"),
			"time" => $odc->date("H:i:s"),
			"confirmInvoice" => 1,
			"allowDuplicateNumbers" => 0,
			"paymentTypeID" => get_option("selected_orders_payment_type"),
			"sendByEmail" => 0,
			"isCashInvoice" => 0,
			"deliveryTypeID" => $deliveryTypeID,
			"request" => "saveSalesDocument",
			"customNumber" => $order_id,
			"currencyCode" => $currency,
		];

        $email = $order->get_billing_email();
        if ($email !== "") {
            $customer_id = $this->save_customer($order);
            if (empty($customer_id)) {
                $this->settings->set_status_sync_failed();
                Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");
                return false;
            }
            $addresses = $this->sync_addresses($order, $customer_id);
            if (empty($addresses)) {
                $this->settings->set_status_sync_failed();
                return false;
            }
            $parameters = array_merge($parameters, $addresses);
        } else {
            Woo_Erply_Main::write_to_log_file("Warning! Order made as guest/without customer email. Saving order without associated customer and address.");
        }



		$invoice_lines = [];

		$order_items = $order->get_items();

		$i = 1;
		foreach ($order_items as $order_item) {
			$v_id = $order_item->get_variation_id();
			$p_id = $order_item->get_product_id();


			if (empty($v_id) && empty($p_id)) {

				Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

				$quantity = $order_item->get_quantity();
				$price = $order_item->get_subtotal() / $quantity;

				$parameters["itemName" . $i] = $order_item->get_name();
				$parameters["vatrateID" . $i] = get_option("default_vat_rate_id");
				$parameters["amount" . $i] = $quantity;
				$parameters["price" . $i] = (get_option('woocommerce_calc_taxes') == "yes") ? $price : ($price / (1 + ($this->vat_rate / 100)));

			} else {
				// $allw_unsync_products = get_option("allw_unsync_products");
				$allw_unsync_products = 1;
				if (!empty($allw_unsync_products)) {
					Woo_Erply_Main::write_to_log_file("Allowing unsynchronized products");
				}


				$erply_oi_id = (!empty($v_id)) ? $v_id : $p_id;
				$erply_product_id = get_post_meta($erply_oi_id, "erply_product_id", true);

				if (empty($erply_product_id)) {
					if (empty($allw_unsync_products) || $allw_unsync_products == 0) {
						add_action('erply_notices', function () {
							echo '<div class="updated error is-dismissible"><p>Synchronization failed because one of the products includes non synced product.</p></div>';
						});

						$date = date("Y-m-d H:i:s");
						$details = "Order ID: " . $order_id . ", Product ID: " . $p_id;
						if (!empty($v_id)) {
							$details .= ", Variation ID: " . $v_id;
						}

						Woo_Erply_Main::write_to_log_file($date . " - Orders synchronization faild. Product/Variation in order items is not synced with erply.");
						Woo_Erply_Main::write_to_log_file($details);
						Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

						$this->settings->set_status_sync_failed();
						return false;
					}
				} else {
					$invoice_lines["productID" . $i] = $erply_product_id;
				}

				$quantity = $order_item->get_quantity();
				$price = $order_item->get_subtotal() / $quantity;

				$invoice_lines["vatrateID" . $i] = get_option("default_vat_rate_id");
				$invoice_lines["amount" . $i] = $quantity;
				$invoice_lines["price" . $i] = (get_option('woocommerce_calc_taxes') == "yes") ? $price : ($price / (1 + ($this->vat_rate / 100)));

				Woo_Erply_Main::write_to_log_file("Product ID: " . $invoice_lines["productID" . $i]);
				Woo_Erply_Main::write_to_log_file("Price: " . $invoice_lines["price" . $i]);

			}

			$i++;
		}
		Woo_Erply_Main::write_to_log_file("Order paid on: " . $order->get_date_paid());

		$parameters = array_merge($parameters, $invoice_lines);

		$promotion_rules = $this->erply_calculate_shopping_cart($order, $invoice_lines);

		$parameters = array_merge($parameters, $promotion_rules);

		$shipping_method = $order->get_shipping_method();

		if (empty($shipping_method)) {
			$shipping_method = $order->get_meta("_shipping_method");
		}

		if (!empty($shipping_method)) {
			$parameters["itemName" . $i] = $shipping_method;
			$parameters["vatrateID" . $i] = get_option("default_vat_rate_id");
			$parameters["amount" . $i] = 1;

			$shipping_total = $order->get_shipping_total();

			if ( !empty( $shipping_total ) ) {
				$parameters["price" . $i] = (get_option('woocommerce_calc_taxes') == "yes") ? $shipping_total : ($shipping_total / (1 + ($this->vat_rate / 100)));
			} else {
				$parameters["price" . $i] = 0;
			}
		}

		$requests = $parameters;

		$result = $this->settings->send_request_to_erply($requests);

		if (!empty($result["success"]) &&
			!empty($result["response"]) &&
			!empty($result["response"]->records)
		) {
			$result_request = $result["response"];
			if (!empty($result_request->status) &&
				$result_request->status->responseStatus == "ok" &&
				!empty($result_request->records) &&
				!empty($result_request->records[0]->invoiceID)
			) {
				$records = $result_request->records;

				update_post_meta($order_id, "erply_order_id", $records[0]->invoiceID);
				update_post_meta($order_id, "erply_invoice_link", $records[0]->invoiceLink);

				Woo_Erply_Main::write_to_log_file("Order No: " . $order_id . " synchronized.");

				if (!empty($order->get_date_paid())) {
					$this->sync_payment_info($order, $records[0]->invoiceID);
				}

			} else {
				$this->settings->set_status_sync_failed();

				Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

				return false;
			}
		} else {
			$this->settings->set_status_sync_failed();

			Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

			return false;
		}

		Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

		return true;
	}

	/**
	 * Handler for immediate order synchronization
	 *
	 * @param $post_ID
	 * @param $post
	 * @param $update
	 */
	public function immediate_order_sync($post_ID, $post)
	{

		if (empty(get_post_meta($post_ID, "erply_order_id", true))) {

			$wc_order = wc_get_order($post_ID);

			if (empty($wc_order) || is_wp_error($wc_order)) {
				return;
			}

			$order = wp_json_encode([$post_ID]);
			update_option("single_order_to_sync", $order);
			wp_schedule_single_event(10, 'woo_erply_sync_single_order');

		}

	}

	/**
	 * Function to check, whether a foreing currency exists in Erply
	 */
	public function checkCurrency($order)
	{
		if (empty($this->erply_currencies)) {
			$this->erply_currencies = $this->get_erply_currencies_list();
		};

		$erply_currencies = $this->erply_currencies;
		$order_currency = $order->get_currency();
		if (array_key_exists($order_currency, $erply_currencies)) {
			return $order_currency;
		} else {
			$this->settings->set_status_sync_failed();
			Woo_Erply_Main::write_to_log_file("Currency " . $order_currency . " not set up in Erply.");
			return false;
		}

	}


	/**
	 * Hook used to synchronize single order if it failed to sync with $this->immediate_order_sync()
	 */
	public function sync_single_order_by_id()
	{
		$ids = json_decode(get_option("single_order_to_sync", 0), true);

		if (!empty($ids)) {
			foreach ($ids as $key => $id) {
				$order = wc_get_order($id);

				if (!empty($order)) {
					$result = $this->sync_single_order($order);

					if ($result) {
						unset($ids[$key]);
					} else {
						break;
					}
				}
			}

			update_option("single_order_to_sync", wp_json_encode($ids));
		}
	}

	/**
	 * Synchronize order customer with erply. Create new one or update if it already exists
	 *
	 * @param WC_Order $order
	 * @return bool|int
	 */
	public function save_customer($order)
	{
		$parameters = [];
		$activity = "update";
		$order_id = $order->get_id();
		$woo_user_id = $order->get_user_id();
		$erply_customer_id = get_post_meta($order_id, "erply_customer_id", true);

		$erply_customer = $this->get_erply_customer($order);
		$erply_customer_id = $erply_customer->customerID;
		if (!empty($erply_customer)) {

			$first_name = $order->get_billing_first_name();
			$last_name = $order->get_billing_last_name();
			$email = $order->get_billing_email();
			$phone = $order->get_billing_phone();
			$country_id = $this->settings->get_erply_country_id_by_code($order->get_billing_country());

			if ($erply_customer->firstName != $first_name) {
				$parameters["firstName"] = $first_name;
			}

			if ($erply_customer->lastName != $last_name) {
				$parameters["lastName"] = $last_name;
			}

			if ($erply_customer->email != $email) {
				$parameters["email"] = $email;
			}

			if ($erply_customer->phone != $phone) {
				$parameters["phone"] = $phone;
			}

			if (!empty($country_id) && ($erply_customer->countryID != $country_id)) {
				$parameters["countryID"] = $country_id;
			}

		} else {
			$activity = "create";
			$parameters = [
				"firstName" => $order->get_billing_first_name(),
				"lastName" => $order->get_billing_last_name(),
				"email" => $order->get_billing_email(),
				"phone" => $order->get_billing_phone(),
			];

			$conf_parameters = $this->settings->get_erply_conf_parameters();

			if (!empty($conf_parameters->enable_waybill_customers)) {
				$parameters["shipGoodsWithWaybills"] = 0;
			}

			$country_id = $this->settings->get_erply_country_id_by_code($order->get_billing_country());

			if (!empty($country_id)) {
				$parameters["countryID"] = $country_id;
			}
		}

		if (!empty($parameters)) {
			if (!empty($erply_customer_id)) {
				$parameters["customerID"] = $erply_customer_id;
			} else if (!empty($woo_user_id)) {
				$parameters["integrationCode"] = $woo_user_id;
			}

			$parameters["request"] = "saveCustomer";

			$requests = $parameters;

			$result = $this->settings->send_request_to_erply($requests);


			if ($result["success"] &&
				!empty($result["response"]) &&
				!empty($result["response"]->records)
			) {
				$result_request = $result["response"];
				if (!empty($result_request->status) &&
					$result_request->status->responseStatus == "ok" &&
					!empty($result_request->records) &&
					!empty($result_request->records[0]->customerID)
				) {
					$records = $result_request->records;
					update_post_meta($order_id, "erply_customer_id", $records[0]->customerID);
					Woo_Erply_Main::write_to_log_file("Customer " . $records[0]->customerID . " assigned to Order " . $order_id);
					$this->log_customer_data_usage($records[0]->customerID, $activity);
					return $records[0]->customerID;
				} else {
					$this->settings->set_status_sync_failed();
					Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");
					return false;
				}
				//}
			} else {
				$this->settings->set_status_sync_failed();
				Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");
				return false;
			}

		}

		return $erply_customer_id;

	}

	/**
	 * Get ErplyCustomer Info
	 *
	 * @param WC_Order $order
	 * @param bool $include_address
	 * @return array|object|bool
	 */
	public function get_erply_customer($order, $include_address = false)
	{
		$customer = [];
		$parameters = [
			"request" => "getCustomers",
		];
		$customer_id = get_post_meta($order->get_id(), "erply_customer_id", true);
		$ids = "";

		if (!empty($customer_id)) {
			$parameters["customerID"] = $customer_id;
		} else {
			$parameters["searchEmail"] = $order->get_billing_email();
		}

		if ($include_address) {
			$parameters["getAddresses"] = "1";
		}

		$requests = $parameters;

		$result = $this->settings->send_request_to_erply($requests);

		if (
			!empty($result["response"]) &&
			!empty($result["response"]->records[0]->customerID)
		) {
			$result_request = $result["response"];
			$customer = $result_request->records[0];

			if (isset($result_request->records) && (sizeof($result_request->records) > 0)) {
				foreach ($result_request->records as $record) {
					$ids .= $record->customerID . ",";
				}
				$this->log_customer_data_usage(trim($ids, ","), "read");
			}
		} else {
			$this->settings->set_status_sync_failed();

			Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

			return false;
		}

        if ($customer === $order->get_billing_email() && $customer !== "") {
            return $customer;
        } else {
            return false;
        }


	}

	/**
	 * Log actions done with users data
	 *
	 * @param $ids
	 * @param $activity
	 */
	public function log_customer_data_usage($ids, $activity)
	{
		$request = [
			"request" => "logProcessingOfCustomerData",
			"customerIDs" => $ids,
			"activityType" => $activity,
			"description" => "Sync with webstore",
			"fields" => ($activity == "read") ? "email" : "all",
		];

		$this->settings->send_request_to_erply($request);
	}

	/**
	 * @param WC_Order $order
	 * @param $erply_order_id
	 */
	public function sync_payment_info($order, $erply_order_id)
	{
		$parameters = [
			"documentID" => $erply_order_id,
			"typeID" => get_option("selected_orders_payment_type"),
			"date" => $order->get_date_paid(),
			"sum" => $order->get_total(),
			"request" => "savePayment",
		];

		if (!empty($order->get_transaction_id())) {
			$parameters["info"] = $order->get_transaction_id();
		}

		$order_currency = $order->get_currency();
		if (in_array($order_currency, $this->erply_currencies)) {
			$parameters["currencyCode"] = $order_currency;
		}

		$this->settings->send_request_to_erply($parameters);
	}

	/**
	 * @param WC_Order $order
	 * @param int $customer_id
	 * @return array
	 */
	public function sync_addresses($order, $customer_id)
	{
		$parameters = [];
		$erply_customer = $this->get_erply_customer($order, true);
		// Prepare billing address data
		$parameters_billing = [
			"ownerID" => $customer_id,
			"typeID" => get_option("erply_billing_address_type_id"),
			"street" => $order->get_billing_address_1(),
			"city" => $order->get_billing_city(),
			"postalCode" => $order->get_billing_postcode(),
			"country" => $order->get_billing_country(),
			"attributeName1" => "company",
			"attributeType1" => "string",
			"attributeValue1" => $order->get_billing_company(),
		];

		if (!empty($order->get_billing_address_2())) {
			$parameters_billing["street"] .= ', ' . $order->get_billing_address_2();
		}

		if (!empty($order->get_billing_state())) {
			$parameters_billing["street"] .= ', ' . $order->get_billing_state();
		}

		// Prepare shipping address data
		$parameters_shipping = [
			"ownerID" => $customer_id,
			"typeID" => get_option("erply_shipping_address_type_id"),
			"street" => $order->get_shipping_address_1(),
			"city" => $order->get_shipping_city(),
			"postalCode" => $order->get_shipping_postcode(),
			"country" => $order->get_shipping_country(),
			"attributeName1" => "company",
			"attributeType1" => "string",
			"attributeValue1" => $order->get_shipping_company(),
		];

		if (!empty($order->get_shipping_address_2())) {
			$parameters_shipping["street"] .= ', ' . $order->get_shipping_address_2();
		}

		if (!empty($order->get_shipping_state())) {
			$parameters_shipping["street"] .= ', ' . $order->get_shipping_state();
		}

		// If customer has no addresses yet - save them in erply

		if (empty($erply_customer->addresses)) {
			$billing_address_id = $this->sync_single_address($parameters_billing);
			$shipping_address_id = $this->sync_single_address($parameters_shipping);
		} else {
			// If customer has addresses in erply than compare current billing and shipping address against the ones in erply
			// If match found - use those for order sync, if not - save new address for customer and use gained addressIDs
			foreach ($erply_customer->addresses as $address) {

				if ($address->typeID == get_option("erply_billing_address_type_id")) {

					if (
						$address->street == $parameters_billing["street"] &&
						$address->city == $parameters_billing["city"] &&
						$address->postalCode == $parameters_billing["postalCode"] &&
						$address->country == $parameters_billing["country"]
					) {
						$billing_address_id = $address->addressID;
					}

				}

				if ($address->typeID == get_option("erply_shipping_address_type_id")) {

					if (
						$address->street == $parameters_shipping["street"] &&
						$address->city == $parameters_shipping["city"] &&
						$address->postalCode == $parameters_shipping["postalCode"] &&
						$address->country == $parameters_shipping["country"]
					) {
						$shipping_address_id = $address->addressID;
					}

				}

			}

			if (empty($billing_address_id)) {
				$billing_address_id = $this->sync_single_address($parameters_billing);
			}

			if (empty($shipping_address_id)) {
				$shipping_address_id = $this->sync_single_address($parameters_shipping);
			}

		}

		if (!empty($billing_address_id) && !empty($shipping_address_id)) {
			$conf_parameters = $this->settings->get_erply_conf_parameters();

			if (!empty($conf_parameters->invoice_client_is_payer)) {
				$parameters["customerID"] = $customer_id;
				$parameters["addressID"] = $billing_address_id;
				$parameters["shipToID"] = $customer_id;
				$parameters["shipToAddressID"] = $shipping_address_id;
			} else {
				$parameters["payerID"] = $customer_id;
				$parameters["addressID"] = $shipping_address_id;
				$parameters["payerAddressID"] = $billing_address_id;
			}

		}

		return $parameters;
	}

	/**
	 * Save billing|shipping address in erply. Return saved address ID or false on failure
	 *
	 * @param array $parameters
	 * @return bool|int
	 */
	public function sync_single_address($parameters)
	{
		$parameters["request"] = "saveAddress";
		$requests = $parameters;

		$result = $this->settings->send_request_to_erply($requests);

		if (!empty($result["success"]) &&
			!empty($result["response"]) &&
			!empty($result["response"]->records)
		) {
			$result_request = $result["response"];
			if (!empty($result_request->status) &&
				$result_request->status->responseStatus == "ok" &&
				!empty($result_request->records) &&
				!empty($result_request->records[0]->addressID)
			) {

				return $result_request->records[0]->addressID;

			}
		}

		$this->settings->set_status_sync_failed();

		Woo_Erply_Main::write_to_log_file("Failed to sync address.");
		Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

		return false;
	}

	/**
	 * Calculate discount in Erply and get promotion rules to use in order sync
	 *
	 * @param WC_Order $order
	 * @return array|bool
	 */
	public function erply_calculate_shopping_cart($order, $invoice_lines)
	{
		$coupon_codes = $order->get_used_coupons();
		$campaign_ids = [];
		$promotion_rules = [];

		foreach ($coupon_codes as $code) {
			$campaign_ids[] = get_post_meta(wc_get_coupon_id_by_code($code), "erply_campaign_id", true);
		}

		if (empty($campaign_ids)) {
			return [];
		}

		$parameters = [
			"manualPromotionIDs" => implode(",", $campaign_ids),
			"request" => "calculateShoppingCart",
		];
		$requests = array_merge($parameters, $invoice_lines);

		$result = $this->settings->send_request_to_erply($requests);

		if (!empty($result["success"]) &&
			!empty($result["response"]) &&
			!empty($result["response"]->records)
		) {
			foreach ($result["response"]->records[0]->rows as $result_request) {

				$rows = $result_request->records[0]->rows;

				foreach ($rows as $row) {
					foreach ($row as $key => $value) {
						$no = $value->rowNumber;
						$sum = $value->promotionPrice;
						$promotion_rules["price" . $no] = $sum;

					}
				}
			}

		} else {
			self::unschedule_synchronization();

			$this->settings->set_status_sync_failed();

			Woo_Erply_Main::write_to_log_file("Failed to calculate shopping cart.");
			Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

			return false;
		}

		return $promotion_rules;
	}

	/**
	 * Synchronize discount coupons
	 *
	 * @return bool
	 */
	public function sync_coupons()
	{
		$requests = [];
		$coupons = $this->get_list_of_woo_coupons();
		$x = 0;
		$y = 1;

		if (empty($coupons)) {
			return true;
		}

		foreach ($coupons as $coupon) {
			$parameters = $this->prepare_single_coupon_request($coupon);

			if (!empty($parameters)) {
				$requests[$x][$y] = $parameters;

				if ($y == 100) {
					$x++;
					$y = 1;
				} else {
					$y++;
				}
			}
		}

		foreach ($requests as $key => $request) {
			$result = $this->settings->send_request_to_erply(["requests" => $request]);

			if (!empty($result["success"]) &&
				!empty($result["response"]) &&
				!empty($result["response"]->requests)
			) {
				foreach ($result["response"]->requests as $result_request) {
					if (!empty($result_request->status) &&
						$result_request->status->responseStatus == "ok" &&
						!empty($result_request->records) &&
						!empty($result_request->records[0]->campaignID)
					) {
						update_post_meta($result_request->status->requestID, "erply_campaign_id", $result_request->records[0]->campaignID);
						Woo_Erply_Main::write_to_log_file("Coupon with ID=" . $result_request->status->requestID . " synchronized and got erply_campaign_id " . $result_request->records[0]->campaignID);
					} else {
						$sync_error = true;
						Woo_Erply_Main::write_to_log_file("Coupon with ID=" . $result_request->status->requestID . "' FAILED to synchronize with error code: " . $result_request->status->errorCode);
					}
				}

				if (!empty($sync_error)) {
					$this->settings->set_status_sync_failed();

					Woo_Erply_Main::write_to_log_file("Failed to sync coupon.");
					Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

					return false;
				}

				unset($requests[$key]);

			} else {
				$this->settings->set_status_sync_failed();

				Woo_Erply_Main::write_to_log_file("Failed to sync coupon.");
				Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");

				return false;
			}

			return true;
		}
		if (sizeof($requests) == 0) {
			Woo_Erply_Main::write_to_log_file("No coupuns (promotions) to sync or they are already synced.");
		}
	}

	/**
	 * Save single discount coupon in erply as a campaign
	 *
	 * @param $coupon
	 * @return array
	 */
	public function prepare_single_coupon_request($coupon)
	{
		$wc_coupon = new WC_Coupon($coupon->ID);
		$campaign_id = get_post_meta($coupon->ID, "erply_campaign_id", true);

		if (empty($wc_coupon)) {
			return false;
		}

		$parameters = [
			"startDate" => $wc_coupon->get_date_created(),
			"endDate" => $wc_coupon->get_date_expires(),
			"name" => $wc_coupon->get_code(),
			"type" => "manual",
			"warehouseID" => get_option("woo_erply_sync_warehouse"),
			"excludeDiscountedFromPercentageOffEntirePurchase" => 0,
			"requestName" => "saveCampaign",
			"requestID" => $coupon->ID,
		];

		if (!empty($campaign_id)) {
			$parameters["campaignID"] = $campaign_id;
			return false;
			// needs actual handling
		}

		$product_ids = $wc_coupon->get_product_ids();
		$erply_ids = [];

		foreach ($product_ids as $product_id) {
			$erply_id = get_post_meta($product_id, "erply_product_id", true);

			if (!empty($erply_id)) {
				$erply_ids[] = $erply_id;
			} else {
				Woo_Erply_Main::write_to_log_file("Warning! Product " . get_the_title($product_id) . ", which is associated with coupon " . $wc_coupon->get_code() . " is not synced to Erply.");
				Woo_Erply_Main::write_to_log_file("- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -");
			}
		}

		$minimum = $wc_coupon->get_minimum_amount();
		if (empty($minimum)) {
			$minimum = 1;
		}
		$parameters["purchaseTotalValue"] = $minimum;

		switch ($wc_coupon->get_discount_type()) {
			case "percent":
				$parameters["percentageOffEntirePurchase"] = $wc_coupon->get_amount();

				if (!empty($erply_ids)) {
					$parameters["percentageOffIncludedProducts"] = implode(",", $erply_ids);
				}

				break;
			case "fixed_cart":
				$parameters["sumOffEntirePurchase"] = $wc_coupon->get_amount();

				if (!empty($erply_ids)) {
					$parameters["sumOffIncludedProducts"] = implode(",", $erply_ids);
				}

				break;
			case "fixed_product":
				$parameters["sumOFF"] = $wc_coupon->get_amount();
				if (!empty($erply_ids)) {
					$parameters["awardedProducts"] = implode(",", $erply_ids);
				}
				break;
			default:
				$parameters = [];
				break;
		}

		return $parameters;
	}

	/**
	 * Get list of woocommerce coupons
	 *
	 * @return array
	 */
	public function get_list_of_woo_coupons()
	{
		$coupons_list = [];
		$args = [
			'posts_per_page' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
			'post_type' => 'shop_coupon',
			'post_status' => 'publish',
		];

		$coupons = get_posts($args);

		foreach ($coupons as $coupon) {
			$coupons_list[$coupon->post_name] = $coupon;
		}

		return $coupons_list;
	}
}
