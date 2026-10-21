<?php
// Kun for tests/playground (se CLAUDE.md). Stubb av \Amendo\Customers i
// amendo-core 3.0.1, med samme form på svarene som den ekte koden på
// garcon.no (20.10.2026). Finnes BARE når optionen amendo_test_tellix = '1',
// så de andre suitene ser en butikk uten amendo-core.
//
//   get_tellix_discounts()        [customerGroupId => stdClass {discountPercent, categories}], bare aktive
//   search_customers($e, $t)      [id, groupId, uuid, name, email, phone] eller null
//   get_tellix_customer_groups()  gruppene med navn (den ekte logger hele svaret
//                                 til error_log — derfor telles kallene her)
namespace Amendo;

if (!defined('ABSPATH')) exit;

// Grensen amendo-core henter kundelista med (wp-config på garcon.no).
if (get_option('amendo_test_sync_size') && !defined('AMENDO_CUSTOMER_SYNC_SIZE')) {
    define('AMENDO_CUSTOMER_SYNC_SIZE', (int) get_option('amendo_test_sync_size'));
}

if (get_option('amendo_test_tellix') === '1' && !class_exists(__NAMESPACE__ . '\\Customers')) {
    class Customers {
        /** Testdata: [{id, name, discountPercent, categories, active?}] */
        private static function grupper() {
            return json_decode((string) get_option('amendo_test_tellix_grupper', '[]'), true) ?: [];
        }

        public static function get_tellix_discounts() {
            $ut = [];
            foreach (self::grupper() as $g) {
                if (isset($g['active']) && !$g['active']) continue;
                $o = new \stdClass();
                $o->discountPercent = $g['discountPercent'] ?? 0;
                $o->categories = $g['categories'] ?? [];
                $ut[(int) $g['id']] = $o;
            }
            return $ut;
        }

        public static function get_tellix_customer_groups() {
            update_option('amendo_test_tellix_navnkall', (int) get_option('amendo_test_tellix_navnkall', 0) + 1, false);
            error_log('Tellix-svar (stubb): ' . wp_json_encode(self::grupper()));
            return array_map(function($g) { return (object) ['id' => $g['id'], 'name' => $g['name'] ?? '']; }, self::grupper());
        }

        public static function search_customers($email, $phone) {
            update_option('amendo_test_tellix_kall', (int) get_option('amendo_test_tellix_kall', 0) + 1, false);
            $sist = get_option('amendo_test_tellix_args', []);
            $sist[] = [$email, $phone];
            update_option('amendo_test_tellix_args', $sist, false);
            $sov = (float) get_option('amendo_test_tellix_sov', 0);
            if ($sov > 0) usleep((int) ($sov * 1000000));
            if (get_option('amendo_test_tellix_kast') === '1') throw new \RuntimeException('Tellix nede');
            foreach (json_decode((string) get_option('amendo_test_tellix_kunder', '[]'), true) ?: [] as $k) {
                // Som amendo-core: e-post likt (store/små bokstaver teller), telefon renset.
                $e = !empty($email) && ($k['email'] ?? '') === $email;
                $t = !empty($phone) && ($k['phone'] ?? '') === '+47' . substr(preg_replace('/\D/', '', (string) $phone), -8);
                if ($e || $t) {
                    return ['id' => $k['id'], 'groupId' => $k['groupId'], 'uuid' => $k['uuid'], 'name' => $k['name'] ?? '', 'email' => $k['email'] ?? '', 'phone' => $k['phone'] ?? ''];
                }
            }
            return null;
        }
    }
}
