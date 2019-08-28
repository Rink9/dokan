<?php
namespace WeDevs\Dokan\Order;

/**
* Admin Hooks
*
* @package dokan
*
* @since DOKAN_LITE_SINCE
*/
class Hooks {

    /**
     * Load autometically when class initiate
     *
     * @since DOKAN_LITE_SINCE
     */
    public function __construct() {
        // on order status change
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_change' ), 10, 4 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_sub_order_change' ), 99, 3 );

        // create sub-orders
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'maybe_split_orders' ) );

        // prevent non-vendor coupons from being added
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'ensure_vendor_coupon' ), 10, 2 );

        if ( is_admin() ) {
            add_action( 'woocommerce_process_shop_order_meta', 'dokan_sync_insert_order' );
        }

        // restore order stock if it's been reduced by twice
        add_action( 'woocommerce_reduce_order_stock', array( $this, 'restore_reduced_order_stock' ) );
    }

    /**
     * Update the child order status when a parent order status is changed
     *
     * @global object $wpdb
     *
     * @param integer $order_id
     * @param string $old_status
     * @param string $new_status
     *
     * @return void
     */
    public function on_order_status_change( $order_id, $old_status, $new_status, $order ) {
        global $wpdb;

        // Split order if the order doesn't have parent and sub orders,
        // and the order is created from dashboard.
        if ( empty( $order->post_parent ) && empty( $order->get_meta( 'has_sub_order' ) ) && is_admin() ) {
            // Remove the hook to prevent recursive callas.
            remove_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_change' ), 10 );

            // Split the order.
            $this->maybe_split_orders( $order_id );

            // Add the hook back.
            add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_change' ), 10, 4 );
        }

        // make sure order status contains "wc-" prefix
        if ( stripos( $new_status, 'wc-' ) === false ) {
            $new_status = 'wc-' . $new_status;
        }

        // insert on dokan sync table
        $wpdb->update( $wpdb->prefix . 'dokan_orders',
            array( 'order_status' => $new_status ),
            array( 'order_id' => $order_id ),
            array( '%s' ),
            array( '%d' )
        );

        // if any child orders found, change the orders as well
        $sub_orders = get_children( array( 'post_parent' => $order_id, 'post_type' => 'shop_order' ) );

        if ( $sub_orders ) {
            foreach ( $sub_orders as $order_post ) {
                $order = dokan()->orders->get( $order_post->ID );
                $order->update_status( $new_status );
            }
        }

        // update on vendor-balance table
       $wpdb->update( $wpdb->prefix . 'dokan_vendor_balance',
            array( 'status' => $new_status ),
            array( 'trn_id' => $order_id, 'trn_type' => 'dokan_orders' ),
            array( '%s' ),
            array( '%d', '%s' )
        );
    }

    /**
     * Mark the parent order as complete when all the child order are completed
     *
     * @param integer $order_id
     * @param string $old_status
     * @param string $new_status
     *
     * @return void
     */
    public function on_sub_order_change( $order_id, $old_status, $new_status ) {
        $order_post = get_post( $order_id );

        // we are monitoring only child orders
        if ( $order_post->post_parent === 0 ) {
            return;
        }

        // get all the child orders and monitor the status
        $parent_order_id = $order_post->post_parent;
        $sub_orders      = get_children( array( 'post_parent' => $parent_order_id, 'post_type' => 'shop_order' ) );

        // return if any child order is not completed
        $all_complete = true;

        if ( $sub_orders ) {
            foreach ($sub_orders as $sub) {
                $order = wc_get_order( $sub->ID );

                if ( $order->get_status() != 'completed' ) {
                    $all_complete = false;
                }
            }
        }

        // seems like all the child orders are completed
        // mark the parent order as complete
        if ( $all_complete ) {
            $parent_order = dokan()->orders->get( $parent_order_id );
            $parent_order->update_status( 'wc-completed', __( 'Mark parent order completed when all child orders are completed.', 'dokan-lite' ) );
        }
    }

    /**
     * Monitors a new order and attempts to create sub-orders
     *
     * If an order contains products from multiple vendor, we can't show the order
     * to each seller dashboard. That's why we need to divide the main order to
     * some sub-orders based on the number of sellers.
     *
     * @param int $parent_order_id
     *
     * @return void
     */
    public function maybe_split_orders( $parent_order_id ) {
        $parent_order = dokan()->orders->get( $parent_order_id );

        dokan_log( sprintf( 'New Order #%d created. Init sub order.', $parent_order_id ) );

        if ( $parent_order->get_meta( 'has_sub_order' ) == true ) {

            $args = array(
                'post_parent' => $parent_order_id,
                'post_type'   => 'shop_order',
                'numberposts' => -1,
                'post_status' => 'any'
            );

            $child_orders = get_children( $args );

            foreach ( $child_orders as $child ) {
                wp_delete_post( $child->ID, true );
            }
        }

        $vendors = dokan_get_sellers_by( $parent_order_id );

        // return if we've only ONE seller
        if ( count( $vendors ) == 1 ) {
            dokan_log( '1 vendor only, skipping sub order.');

            $temp      = array_keys( $vendors );
            $seller_id = reset( $temp );

            do_action( 'dokan_create_parent_order', $parent_order, $seller_id );

            // record admin commision
            $admin_fee = dokan_get_admin_commission_by( $parent_order, $seller_id );
            $parent_order->update_meta_data( '_dokan_admin_fee', $admin_fee );
            $parent_order->update_meta_data( '_dokan_vendor_id', $seller_id );
            $parent_order->save();

            // if the request is made from rest api then insert the order data to the sync table
            if ( defined( 'REST_REQUEST' ) ) {
                do_action( 'dokan_checkout_update_order_meta', $parent_order_id, $seller_id );
            }

            return;
        }

        // flag it as it has a suborder
        $parent_order->update_meta_data( 'has_sub_order', true );
        $parent_order->save();

        dokan_log( sprintf( 'Got %s vendors, starting sub order.', count( $vendors ) ) );

        // seems like we've got multiple sellers
        foreach ( $vendors as $seller_id => $seller_products ) {
            dokan()->orders->create_sub_order( $parent_order, $seller_id, $seller_products );
        }

        dokan_log( sprintf( 'Completed sub order for #%d.', $parent_order_id ) );
    }

    /**
     * Ensure vendor coupon
     *
     * For consistancy, restrict coupons in cart if only
     * products from that vendor exists in the cart. Also, a coupon
     * should be restricted with a product.
     *
     * For example: When entering a coupon created by admin is applied, make
     * sure a product of the admin is in the cart. Otherwise it wouldn't be
     * possible to distribute the coupon in sub orders.
     *
     * @param  boolean $valid
     * @param  \WC_Coupon $coupon
     *
     * @return boolean|Execption
     */
    public function ensure_vendor_coupon( $valid, $coupon ) {
        $coupon_id         = $coupon->get_id();
        $vendor_id         = get_post_field( 'post_author', $coupon_id );
        $available_vendors = array();

        if ( ! apply_filters( 'dokan_ensure_vendor_coupon', true ) ) {
            return $valid;
        }

        // a coupon must be bound with a product
        if ( count( $coupon->get_product_ids() ) == 0 ) {
            throw new Exception( __( 'A coupon must be restricted with a vendor product.', 'dokan-lite' ) );
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            $product_id = $item['data']->get_id();

            $available_vendors[] = get_post_field( 'post_author', $product_id );
        }

        if ( ! in_array( $vendor_id, $available_vendors ) ) {
            return false;
        }

        return $valid;
    }

    /**
     * Restore order stock if it's been reduced by twice
     *
     * @param  object $order
     *
     * @return void
     */
    public function restore_reduced_order_stock( $order ) {
        // seems in rest request, there is no such issue like (stock reduced by twice), so return early
        if ( defined( 'REST_REQUEST' ) ) {
            return;
        }

        $has_sub_order = wp_get_post_parent_id( $order->get_id() );

        // seems it's not a parent order so return early
        if ( ! $has_sub_order ) {
            return;
        }

        // Loop over all items.
        foreach ( $order->get_items() as $item ) {
            if ( ! $item->is_type( 'line_item' ) ) {
                continue;
            }

            // Only reduce stock once for each item.
            $product            = $item->get_product();
            $item_stock_reduced = $item->get_meta( '_reduced_stock', true );

            if ( ! $item_stock_reduced || ! $product || ! $product->managing_stock() ) {
                continue;
            }

            $item_name = $product->get_formatted_name();
            $new_stock = wc_update_product_stock( $product, $item_stock_reduced, 'increase' );

            if ( is_wp_error( $new_stock ) ) {
                /* translators: %s item name. */
                $order->add_order_note( sprintf( __( 'Unable to restore stock for item %s.', 'dokan-lite' ), $item_name ) );
                continue;
            }

            $item->delete_meta_data( '_reduced_stock' );
            $item->save();
        }
    }
}
