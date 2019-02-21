<?php

	/**
	 * Plugin Name: WooCommerce Wallet - Referrals
	 * Plugin URI:  https://github.com/boylett/woo-wallet-referrals
	 * Description: Extension for the popular Woo Wallet plugin that adds a simple user referral system to the WW Actions menu
	 * Author:      Ryan Boylett
	 * Author URI:  https://github.com/boylett
	 * Version:     0.0.1
	 */

	defined("ABSPATH") || exit;

	/**
	 * Retrieve a user's referral code
	 * @param  int  $user_id  User ID
	 * @return string         Referral code
	 */
	function ww_referral_get_code($user_id = NULL)
	{
		return apply_filters("ww_referral_get_code", $user_id);
	}

	/**
	 * Retrieve a signup link containing a user's referral code
	 * @param  int     $user_id  User ID
	 * @param  string  $link     Base link on which to append the referral code
	 * @return string            Referral signup link
	 */
	function ww_referral_get_link($user_id = NULL, $link = '')
	{
		return apply_filters("ww_referral_get_link", $user_id, $link);
	}

	/**
	 * Retrieve a list of referred user IDs for the given user ID
	 * @param  int  $user_id  User ID
	 * @return array          List of referred users' IDs
	 */
	function ww_referral_get_referred_users($user_id = NULL)
	{
		return apply_filters("ww_referral_get_referred_users", $user_id);
	}

	/**
	 * Retrieve a user's referrer ID
	 * @param  int  $user_id  User ID
	 * @return int|NULL       Referrer ID
	 */
	function ww_referral_get_referrer($user_id = NULL)
	{
		return apply_filters("ww_referral_get_referrer", $user_id);
	}

	/**
	 * Save a user's referrer ID
	 * @param  int  $user_id      User ID
	 * @param  int  $referrer_id  Referrer ID
	 */
	function ww_referral_save_referrer($user_id, $referrer_id)
	{
		return do_action("ww_referral_save_referrer", $user_id, $referrer_id);
	}

	/**
	 * Wait for the Woo Wallet plugin to load before trying to extend the abstract class `WooWalletAction`
	 */
	add_action("woo_wallet_load_actions", function()
	{
		/**
		 * Check that the class exists first - this action can potentially fire multiple times
		 */
		if(!class_exists("Action_Custom_Referral_Action"))
		{
			/**
			 * Extend upon the abstract class WooWalletAction
			 */
			class Action_Custom_Referral_Action extends WooWalletAction
			{
				/**
				 * Hey look! Variables!
				 */
				private $cookie_name              = "ww_referral_cookie";
				private $referral_code_key        = "ww_referral_code";
				private $referral_code_url_param  = "referrer";
				private $referrer_id_key          = "ww_referral_referrer_id";

				/**
				 * Bind a buncha actions and filters
				 */
				public function __construct()
				{
					$this->id           = "custom_referrals";
					$this->action_title = __("Referrals", "woo-wallet-custom-referral");
					$this->description  = __("Set credit rules for user referrals", "woo-wallet-custom-referral");
					
					$this->init_form_fields();
					$this->init_settings();

					$this->cookie_name = md5(ABSPATH . ":{$this->cookie_name}");

					add_filter("ww_referral_get_code",           array($this, "get_referral_code"), 10, 1);
					add_filter("ww_referral_get_link",           array($this, "get_referral_link"), 10, 2);
					add_filter("ww_referral_get_referred_users", array($this, "get_referred_users"), 10, 1);
					add_filter("ww_referral_get_referrer",       array($this, "get_referrer"), 10, 1);
					add_action("ww_referral_save_referrer",      array($this, "save_referrer"), 10, 2);

					add_filter("ww_referral_reward_user", function($user, $referrer, $amount){ return $user; }, 10, 3);

					add_action("init",              array($this, "save_referrer_cookie"), 9001);
					add_action("register_new_user", array($this, "save_referrer_on_register"), 9001, 1);

					add_action("register_new_user",                array($this, "reward_on_register"), 9002, 1);
					add_action("woocommerce_order_status_changed", array($this, "reward_on_purchase"), 9001, 3);
				}

				/**
				 * Initialize the action's settings form fields
				 */
				public function init_form_fields()
				{
					$this->form_fields = array
					(
						"enabled" => array
						(
							"title"   => __("Enable/Disable", "woo-wallet-custom-referral"),
							"type"    => "checkbox",
							"label"   => __("Enable credit for user referrals.", "woo-wallet-custom-referral"),
							"default" => "no",
						),
						array
						(
							"title" => __("Referral Behaviour", "woo-wallet-custom-referral"),
							"type"  => "title",
							"desc"  => "",
							"id"    => "referral_behaviour",
						),
						"require_purchase" => array
						(
							"title"   => __("Require Purchase", "woo-wallet-custom-referral"),
							"type"    => "checkbox",
							"label"   => __("Require the referred user to make a purchase before rewarding referral credit to referrer.", "woo-wallet-custom-referral"),
							"default" => "no",
						),
						"require_purchase_threshhold" => array
						(
							"title"       => __("Purchase Threshhold", "woo-wallet-custom-referral"),
							"type"        => "price",
							"description" => __("If a purchase is required, only reward credit if this amount or more is spent.", "woo-wallet-custom-referral"),
							"default"     => "10",
							"desc_tip"    => true
						),
						array
						(
							"title" => __("Referring Signups", "woo-wallet-custom-referral"),
							"type"  => "title",
							"desc"  => "",
							"id"    => "referring_signups",
						),
						"referring_signups_amount" => array
						(
							"title"       => __("Amount", "woo-wallet-custom-referral"),
							"type"        => "price",
							"description" => __("Enter amount which will be credited to the user wallet for successful referrals.", "woo-wallet-custom-referral"),
							"default"     => "10",
							"desc_tip"    => true
						),
						"referring_signups_description" => array
						(
							"title"       => __("Description", "woo-wallet-custom-referral"),
							"type"        => "textarea",
							"description" => __("Wallet transaction description that will display as transaction note.<br /><i>(%s is referrer username)</i>", "woo-wallet-custom-referral"),
							"default"     => __("Referred by %s.", "woo-wallet-custom-referral"),
							"desc_tip"    => true,
						)
					);
				}

				/**
				 * Saves the referrer ID as a cookie if it's set in an HTTP request
				 */
				public function save_referrer_cookie()
				{
					if(!is_user_logged_in() and isset($_REQUEST[$this->referral_code_url_param]))
					{
						global $wpdb;

						$referrer = get_users(array
						(
							"fields"     => "ids",
							"meta_query" => array
							(
								array
								(
									"key"   => $this->referral_code_key,
									"value" => $_REQUEST[$this->referral_code_url_param],
								)
							),
						));

						if(!empty($referrer))
						{
							setcookie($this->cookie_name, reset($referrer), current_time("timestamp") + WEEK_IN_SECONDS);
						}
					}
				}

				/**
				 * Saves a user's referrer ID to their profile when they sign up
				 * @param  int  $user_id  User ID
				 */
				public function save_referrer_on_register($user_id)
				{
					if(isset($_COOKIE[$this->cookie_name]) and trim($_COOKIE[$this->cookie_name]))
					{
						$referrer = get_user_by("id", $_COOKIE[$this->cookie_name]);

						if($referrer->ID)
						{
							setcookie($this->cookie_name, '', time() - DAY_IN_SECONDS);

							$this->save_referrer($user_id, $referrer->ID);
						}
					}
				}

				/**
				 * Rewards a user's referrer when they sign up
				 * @param  int  $user_id  User ID
				 */
				public function reward_on_register($user_id)
				{
					if($this->is_enabled() and $this->settings["require_purchase"] == "no" and $this->settings["referring_signups_amount"] > 0)
					{
						$user     = get_user_by("id", $user_id);
						$referrer = get_user_by("id", $this->get_referrer($user->ID));

						if($user->ID and $referrer->ID)
						{
							if(apply_filters("ww_referral_reward_user", $user, $referrer, $this->settings["referring_signups_amount"]) !== false)
							{
								woo_wallet()->wallet->credit($referrer->ID, $this->settings["referring_signups_amount"], sanitize_textarea_field(sprintf(__("Referred user %s signed up", "woo-wallet-custom-referral"), $user->user_login)));
							}
						}
					}
				}

				/**
				 * Rewards a customer's referrer when an order's status changes to 'completed' and they have satisfied the threshhold (if any)
				 * @param  int     $order_id    Order ID
				 * @param  string  $old_status  Old order status
				 * @param  string  $new_status  New order status
				 */
				public function reward_on_purchase($order_id, $old_status, $new_status)
				{
					if($this->is_enabled() and $this->settings["require_purchase"] == "yes" and $this->settings["referring_signups_amount"] > 0 and $new_status == "completed")
					{
						$order = wc_get_order($order_id);

						if($order and $order->get_id())
						{
							$user     = get_user_by("id", $order->get_customer_id());
							$referrer = get_user_by("id", $this->get_referrer($user->ID));

							if($user->ID and $referrer->ID)
							{
								$already_rewarded = get_user_meta($user->ID, "ww_referral_referrer_rewarded_for_purchase", true);
								
								if(!$already_rewarded and !($this->settings["require_purchase_threshhold"] > 0 and wc_get_user_value($user->ID) < $this->settings["require_purchase_threshhold"]))
								{
									woo_wallet()->wallet->credit($referrer->ID, $this->settings["referring_signups_amount"], sanitize_textarea_field(sprintf(__("Referred user %s made a purchase", "woo-wallet-custom-referral"), $user->user_login)));

									update_user_meta($user->ID, "ww_referral_referrer_rewarded_for_purchase", $order_id);
								}
							}
						}
					}
				}

				/**
				 * Retrieves a user's referral code
				 * @param  int  $user_id  User ID
				 * @return string         Referral code
				 */
				public function get_referral_code($user_id)
				{
					if(empty($user_id))
					{
						$user_id = get_current_user_id();
					}

					$code = '';

					if($user_id)
					{
						$code = trim(get_user_meta($user_id, $this->referral_code_key, true));

						if(empty($code))
						{
							$code = hash("CRC32B", date("Y-m-d H:i:s") . ":{$this->referral_code_key}:{$user_id}");

							update_user_meta($user_id, $this->referral_code_key, $code);
						}
					}

					return $code;
				}

				/**
				 * Retrieve a signup link containing a user's referral code
				 * @param  int     $user_id  User ID
				 * @param  string  $link     Base link on which to append the referral code
				 * @return string            Referral signup link
				 */
				public function get_referral_link($user_id, $link = '')
				{
					$code = $this->get_referral_code($user_id);
					$link = trim($link);

					if(!$link)
					{
						$link = wp_registration_url();
					}

					if(strstr($link, "?"))
					{
						$link .= "&";
					}
					else
					{
						$link .= "?";
					}

					return "{$link}{$this->referral_code_url_param}={$code}";
				}

				/**
				 * Retrieves a list of referred user IDs for the given user ID
				 * @param  int  $user_id  User ID
				 * @return array          List of referred users' IDs
				 */
				public function get_referred_users($user_id = NULL)
				{
					global $wpdb;

					if(empty($user_id))
					{
						$user_id = get_current_user_id();
					}

					$user_id = esc_sql($user_id);

					return $wpdb->get_col("
						SELECT
							user_id
						FROM
							{$wpdb->usermeta}
						WHERE
							meta_key = '{$this->referrer_id_key}'
							AND
							meta_value = '{$user_id}'
					");
				}

				/**
				 * Retrieves a user's referrer ID
				 * @param  int  $user_id  User ID
				 * @return int|NULL       Referrer ID
				 */
				public function get_referrer($user_id = NULL)
				{
					if(empty($user_id))
					{
						$user_id = get_current_user_id();
					}

					$referrer = get_user_meta($user_id, $this->referrer_id_key, true);

					if($referrer)
					{
						return $referrer;
					}

					return NULL;
				}

				/**
				 * Saves a user's referrer ID
				 * @param  int  $user_id      User ID
				 * @param  int  $referrer_id  Referrer ID
				 */
				public function save_referrer($user_id, $referrer_id)
				{
					update_user_meta($user_id, $this->referrer_id_key, $referrer_id);
				}
			}
		}
	});

	add_filter("woo_wallet_actions", function($actions)
	{
		$actions[] = "Action_Custom_Referral_Action";

		return $actions;
	});
