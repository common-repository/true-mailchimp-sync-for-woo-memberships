<?php
/*
Plugin Name: MailChimp Sync for WooCommerce Memberships
Plugin URI: https://rudrastyh.com/plugins/mailchimp-synchronization
Description: Allows to sync users with every status of your WooCommerce Memberships plans with MailChimp lists.
Version: 1.0
Text Domain: true-mailchimp-sync-for-woo-memberships
Author: Misha Rudrastyh
Author URI: https://rudrastyh.com

Copyright 2014-2019 Misha Rudrastyh ( https://rudrastyh.com )

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

require_once( dirname( __FILE__ ) . '/class-misha-mailchimp-api.php' );
require_once( dirname( __FILE__ ) . '/class-misha-mailchimp-options.php' );
