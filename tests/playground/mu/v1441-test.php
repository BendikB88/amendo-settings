<?php
/**
 * 1.4.41 — beløpssikringen. Stubber gatewayens Adyen-webhook
 * (`POST aorder/v1/amendo-adyen`) slik woocommerce-amendo-gateway 1.30.2
 * gjør det: finn ordren på merchantReference og kall payment_complete() ved
 * AUTHORISATION med success. Se suiter/belopssikring.mjs.
 *
 * Kun for tests/playground (se CLAUDE.md), aldri i en ekte butikk.
 */
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    if (class_exists('AOrder\Gateways\Webhook')) return; // den ekte gatewayen
    register_rest_route('aorder/v1', '/amendo-adyen', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($req) {
        $kropp = json_decode((string) $req->get_body(), true);
        foreach (($kropp['notificationItems'] ?? []) as $element) {
            $n = $element['NotificationRequestItem'] ?? [];
            if (($n['eventCode'] ?? '') !== 'AUTHORISATION' || ($n['success'] ?? '') !== 'true') continue;
            $o = wc_get_order((int) preg_replace('~^.*/~', '', (string) ($n['merchantReference'] ?? '')));
            if ($o) $o->payment_complete((string) ($n['pspReference'] ?? ''));
        }
        return '[accepted]';
    }]);
    // payment_complete() uten webhook (en annen gateway, wp-admin): skal ikke røres.
    register_rest_route('amendo-test/v1', '/betal-direkte', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($req) {
        $o = wc_get_order((int) $req->get_param('id'));
        $o->payment_complete('DIREKTE');
        return ['status' => wc_get_order($o->get_id())->get_status()];
    }]);
});
