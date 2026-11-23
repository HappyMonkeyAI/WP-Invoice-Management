<?php
/**
 * Registers the wp-invoice/* abilities exposed to AI agents over MCP.
 *
 * Every ability delegates to the existing wp-invoice/v1 REST routes so the
 * plugin's permission checks and author scoping apply unchanged. Destructive
 * operations (delete, bulk-delete, bulk status, backup import, data reset) are
 * deliberately not registered.
 *
 * @package Wpim\Invoice\Abilities
 */

namespace Wpim\Invoice\Abilities;

class Registrar {

    const CATEGORY = 'wp-invoice';

    public function __construct() {
        add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
        add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
    }

    public function register_category() {
        wp_register_ability_category( self::CATEGORY, array(
            'label'       => __( 'Invoicing', 'wp-invoice-management' ),
            'description' => __( 'Read and update invoices, customers and invoice PDFs.', 'wp-invoice-management' ),
        ) );
    }

    public function register_abilities() {
        $this->register( 'wp-invoice/list-invoices', array(
            'label'       => __( 'List invoices', 'wp-invoice-management' ),
            'description' => __( 'List invoices visible to the current user. Returns id, title, status, client, dates and totals. Use this to find an invoice id before reading or updating it. Filter by client (substring of the billed-to name, e.g. "Ellis" or "Camden") and/or item_search (substring of a line item description, e.g. "hosting"); search only matches the invoice title. Order by invoice_number to sort on the number in the title (77, 76, 75) rather than the internal post id, which differs; title sorts alphabetically and date sorts on the issued date.', 'wp-invoice-management' ),
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'per_page'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
                    'page'        => array( 'type' => 'integer', 'minimum' => 1 ),
                    'search'      => array( 'type' => 'string' ),
                    'client'      => array( 'type' => 'string' ),
                    'item_search' => array( 'type' => 'string' ),
                    'orderby'     => array( 'type' => 'string', 'enum' => array( 'date', 'due_date', 'total', 'title', 'invoice_number' ) ),
                    'order'       => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ) ),
                ),
            ),
            'meta' => array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ),
            'execute_callback' => function ( $input ) {
                return $this->rest_get( '/invoices', $this->pick( $input, array( 'per_page', 'page', 'search', 'client', 'item_search', 'orderby', 'order' ) ) );
            },
        ) );

        $this->register( 'wp-invoice/get-invoice', array(
            'label'       => __( 'Get invoice', 'wp-invoice-management' ),
            'description' => __( 'Read one invoice in full, including its line items. Always call this before update-invoice so you can see the current line items and their date ranges.', 'wp-invoice-management' ),
            'input_schema' => array(
                'type'       => 'object',
                'required'   => array( 'id' ),
                'properties' => array( 'id' => array( 'type' => 'integer' ) ),
            ),
            'meta' => array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ),
            'execute_callback' => function ( $input ) {
                return $this->rest_get( '/invoices/' . intval( $input['id'] ) );
            },
        ) );

        $this->register( 'wp-invoice/list-customers', array(
            'label'       => __( 'List customers', 'wp-invoice-management' ),
            'description' => __( 'List the customers visible to the current user, with contact and address details.', 'wp-invoice-management' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array() ),
            'meta' => array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ),
            'execute_callback' => function () {
                return $this->rest_get( '/customers' );
            },
        ) );

        $this->register( 'wp-invoice/get-settings', array(
            'label'       => __( 'Get invoice settings', 'wp-invoice-management' ),
            'description' => __( 'Read invoicing defaults such as currency symbol, tax rate and business details.', 'wp-invoice-management' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array() ),
            'meta' => array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ),
            'execute_callback' => function () {
                return $this->rest_get( '/settings' );
            },
        ) );

        $this->register( 'wp-invoice/export-backup', array(
            'label'       => __( 'Export invoice backup', 'wp-invoice-management' ),
            'description' => __( 'Export all invoices and customers visible to the current user as a JSON backup payload. Read-only; use before a batch of edits.', 'wp-invoice-management' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array() ),
            'meta' => array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ),
            'execute_callback' => function () {
                return $this->rest_get( '/backup/export' );
            },
        ) );

        $this->register( 'wp-invoice/create-invoice', array(
            'label'       => __( 'Create invoice', 'wp-invoice-management' ),
            'description' => __( 'Create a new invoice owned by the current user. Line items may be of type "item" (billable, with quantity/rate/date/date_to) or "section" (a heading grouping following items).', 'wp-invoice-management' ),
            'input_schema' => $this->write_schema( false ),
            'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => false ) ),
            'execute_callback' => function ( $input ) {
                return $this->rest_write( 'POST', '/invoices', $this->invoice_payload( $input ) );
            },
        ) );

        $this->register( 'wp-invoice/update-invoice', array(
            'label'       => __( 'Update invoice', 'wp-invoice-management' ),
            'description' => __( 'Update an existing invoice. Omitted scalar fields are left untouched. Line items are merged by position over the stored items, so omitted item keys (notably date and date_to) are preserved rather than erased. Supplying fewer items than are stored is refused unless allow_item_removal is true. Read the invoice first with get-invoice.', 'wp-invoice-management' ),
            'input_schema' => $this->write_schema( true ),
            'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ),
            'execute_callback' => function ( $input ) {
                return $this->update_invoice( $input );
            },
        ) );

        $this->register( 'wp-invoice/update-status', array(
            'label'       => __( 'Set invoice status', 'wp-invoice-management' ),
            'description' => __( 'Set the billing status of one invoice: open, paid, overdue, draft or sent.', 'wp-invoice-management' ),
            'input_schema' => array(
                'type'       => 'object',
                'required'   => array( 'id', 'status' ),
                'properties' => array(
                    'id'     => array( 'type' => 'integer' ),
                    'status' => array( 'type' => 'string', 'enum' => array( 'open', 'paid', 'overdue', 'draft', 'sent', 'chased' ) ),
                ),
            ),
            'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ),
            'execute_callback' => function ( $input ) {
                return $this->rest_write( 'POST', '/invoices/' . intval( $input['id'] ) . '/status', array( 'status' => $input['status'] ) );
            },
        ) );

        $this->register( 'wp-invoice/get-pdf', array(
            'label'       => __( 'Get invoice PDF', 'wp-invoice-management' ),
            'description' => __( 'Render an invoice as a PDF and return it base64-encoded with a suggested filename. Decode content_base64 and write it to disk to save the invoice.', 'wp-invoice-management' ),
            'input_schema' => array(
                'type'       => 'object',
                'required'   => array( 'id' ),
                'properties' => array( 'id' => array( 'type' => 'integer' ) ),
            ),
            'meta' => array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ),
            'execute_callback' => function ( $input ) {
                $id  = intval( $input['id'] );
                $pdf = \Wpim\Invoice\Plugin::instance()->get_pdf_bytes( $id );
                if ( is_wp_error( $pdf ) ) {
                    return $pdf;
                }
                return array(
                    'filename'       => 'invoice-' . $id . '.pdf',
                    'mime'           => 'application/pdf',
                    'size_bytes'     => strlen( $pdf ),
                    'content_base64' => base64_encode( $pdf ),
                );
            },
        ) );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function register( $name, $args ) {
        $args['category']            = self::CATEGORY;
        $args['permission_callback'] = array( $this, 'check_permission' );
        $args['meta']['mcp']['public'] = true;

        wp_register_ability( $name, $args );
    }

    public function check_permission() {
        return current_user_can( 'edit_posts' );
    }

    private function write_schema( $is_update ) {
        $properties = array(
            'title'       => array( 'type' => 'string' ),
            'logo_id'     => array( 'type' => 'integer' ),
            'status'      => array( 'type' => 'string', 'enum' => array( 'open', 'paid', 'overdue', 'draft', 'sent', 'chased' ) ),
            'date'        => array( 'type' => 'string' ),
            'due_date'    => array( 'type' => 'string' ),
            'po_number'   => array( 'type' => 'string' ),
            'from'        => array( 'type' => 'string' ),
            'to'          => array( 'type' => 'string' ),
            'ship_to'     => array( 'type' => 'string' ),
            'notes'       => array( 'type' => 'string' ),
            'terms'       => array( 'type' => 'string' ),
            'tax'         => array( 'type' => 'number' ),
            'discount'    => array( 'type' => 'number' ),
            'shipping'    => array( 'type' => 'number' ),
            'amount_paid' => array( 'type' => 'number' ),
            'items'       => array(
                'type'  => 'array',
                'items' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'description' => array( 'type' => 'string' ),
                        'type'        => array( 'type' => 'string', 'enum' => array( 'item', 'section' ) ),
                        'quantity'    => array( 'type' => 'number' ),
                        'rate'        => array( 'type' => 'number' ),
                        'date'        => array( 'type' => 'string' ),
                        'date_to'     => array( 'type' => 'string' ),
                    ),
                ),
            ),
        );

        if ( $is_update ) {
            $properties['id'] = array( 'type' => 'integer' );
            $properties['allow_item_removal'] = array( 'type' => 'boolean' );
            return array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => $properties );
        }

        return array( 'type' => 'object', 'properties' => $properties );
    }

    private function update_invoice( $input ) {
        $id      = intval( $input['id'] );
        $payload = $this->invoice_payload( $input );

        if ( isset( $input['items'] ) && is_array( $input['items'] ) ) {
            $current = $this->rest_get( '/invoices/' . $id );
            if ( is_wp_error( $current ) ) {
                return $current;
            }

            $stored = isset( $current['items'] ) && is_array( $current['items'] ) ? $current['items'] : array();
            $merged = $this->merge_items( $stored, $input['items'], ! empty( $input['allow_item_removal'] ) );
            if ( is_wp_error( $merged ) ) {
                return $merged;
            }

            $payload['items'] = $merged;
        }

        return $this->rest_write( 'PUT', '/invoices/' . $id, $payload );
    }

    /**
     * Line items are persisted as a whole array, so a partial list from a caller
     * would silently drop the omitted items' fields. Merge by position instead.
     */
    private function merge_items( $stored, $incoming, $allow_removal ) {
        if ( count( $incoming ) < count( $stored ) && ! $allow_removal ) {
            return new \WP_Error(
                'item_removal_blocked',
                sprintf(
                    'Refusing to reduce line items from %d to %d. Pass allow_item_removal=true to confirm removal.',
                    count( $stored ),
                    count( $incoming )
                ),
                array( 'status' => 400 )
            );
        }

        $merged = array();
        foreach ( $incoming as $index => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $base     = isset( $stored[ $index ] ) && is_array( $stored[ $index ] ) ? $stored[ $index ] : array();
            $merged[] = array_merge( $base, $item );
        }

        return $merged;
    }

    private function invoice_payload( $input ) {
        $fields = array( 'title', 'logo_id', 'status', 'date', 'due_date', 'po_number', 'from', 'to', 'ship_to', 'notes', 'terms', 'tax', 'discount', 'shipping', 'amount_paid', 'items' );
        return $this->pick( $input, $fields );
    }

    private function pick( $input, $keys ) {
        $out = array();
        foreach ( $keys as $key ) {
            if ( is_array( $input ) && array_key_exists( $key, $input ) ) {
                $out[ $key ] = $input[ $key ];
            }
        }
        return $out;
    }

    private function rest_get( $route, $params = array() ) {
        $request = new \WP_REST_Request( 'GET', '/wp-invoice/v1' . $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return $this->dispatch( $request );
    }

    private function rest_write( $method, $route, $payload ) {
        $request = new \WP_REST_Request( $method, '/wp-invoice/v1' . $route );
        // The REST callbacks read get_json_params(), so the body must be JSON.
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $payload ) );
        return $this->dispatch( $request );
    }

    private function dispatch( \WP_REST_Request $request ) {
        $response = rest_do_request( $request );
        if ( $response->is_error() ) {
            return $response->as_error();
        }
        return $response->get_data();
    }
}