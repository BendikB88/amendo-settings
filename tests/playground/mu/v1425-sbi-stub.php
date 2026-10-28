<?php
// Kun for tests/playground (se CLAUDE.md). Stubb av Instagram Feed (Smash
// Balloon) 6.x, suiten `instagram` (1.4.25). Finnes bare når optionen
// amendo_test_sbi er '1'. Det amendo-settings bruker, gjort som i
// instagram-feed 6.14.0 (gratisutgaven på wordpress.org, samme kjerne som Pro):
//
// - tabellen {prefix}sbi_sources (opprettes av /v1425-sbi i v1425-test.php)
// - SB_Instagram_Data_Encryption: aes-256-ctr med LOGGED_IN_KEY, salt bakerst
// - sbi_maybe_clean(): dekrypterer, ellers verdien som den er
// - InstagramFeed\Builder\SBI_Source::convert_sources_to_connected_accounts()
// - SB_Instagram_Token_Refresher: grense 40 dager før utløp, minst 600 s
//   mellom forsøk, refresh_access_token, skriver nytt token og utløp i tabellen
//
// Navnerommet krever klammer rundt alt i fila.

namespace InstagramFeed\Builder {
    if (!defined('ABSPATH')) exit;

    if (get_option('amendo_test_sbi') === '1' && !class_exists('InstagramFeed\Builder\SBI_Source')) {
        class SBI_Source {
            public static function convert_sources_to_connected_accounts($rader) {
                $ut = [];
                foreach ($rader as $r) {
                    $info = json_decode((string) ($r['info'] ?? ''), true) ?: [];
                    $k = [
                        'id'                => $r['account_id'],
                        'user_id'           => $r['account_id'],
                        'type'              => $r['account_type'],
                        'account_type'      => $r['account_type'],
                        'username'          => $r['username'],
                        'access_token'      => \sbi_maybe_clean($r['access_token']),
                        'expires_timestamp' => strtotime($r['expires'] . ' UTC'),
                        'connect_type'      => $r['connect_type'] ?? 'personal',
                    ];
                    if (!empty($info['last_refresh_attempt'])) $k['last_refresh_attempt'] = (int) $info['last_refresh_attempt'];
                    $ut[] = $k;
                }
                return $ut;
            }
        }
    }
}

namespace {
    if (!defined('ABSPATH')) exit;

    if (get_option('amendo_test_sbi') === '1' && !function_exists('sbi_maybe_clean')) {
        function sbi_doing_openssl() { return function_exists('openssl_encrypt'); }

        class SB_Instagram_Data_Encryption {
            private $key; private $salt;
            public function __construct() {
                $this->key  = defined('LOGGED_IN_KEY') && LOGGED_IN_KEY !== '' ? LOGGED_IN_KEY : 'das-ist-kein-geheimer-schluessel';
                $this->salt = defined('LOGGED_IN_SALT') && LOGGED_IN_SALT !== '' ? LOGGED_IN_SALT : 'das-ist-kein-geheimes-salz';
            }
            public function encrypt($v) {
                if (!sbi_doing_openssl()) return $v;
                $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-ctr'));
                $r = openssl_encrypt($v . $this->salt, 'aes-256-ctr', $this->key, 0, $iv);
                return $r ? base64_encode($iv . $r) : false;
            }
            public function decrypt($v) {
                if (!sbi_doing_openssl()) return $v;
                $v = base64_decode((string) $v, true);
                if ($v === false) return false;
                $n = openssl_cipher_iv_length('aes-256-ctr');
                $u = openssl_decrypt(substr($v, $n), 'aes-256-ctr', $this->key, 0, substr($v, 0, $n));
                if (!$u || substr($u, -strlen($this->salt)) !== $this->salt) return false;
                return substr($u, 0, -strlen($this->salt));
            }
        }

        function sbi_maybe_clean($v) {
            $d = (new SB_Instagram_Data_Encryption())->decrypt($v);
            return $d ?: $v;
        }

        class SB_Instagram_Token_Refresher {
            private $konto;
            public function __construct($konto) { $this->konto = $konto; }
            public static function refresh_time_has_passed_threshold($k) {
                return (($k['expires_timestamp'] ?? time()) - 40 * 86400) < time();
            }
            public static function minimum_time_interval_since_last_attempt_has_passed($k) {
                return time() > ((int) ($k['last_refresh_attempt'] ?? 0)) + 600;
            }
            public function attempt_token_refresh() {
                global $wpdb;
                $t = $wpdb->prefix . 'sbi_sources';
                $id = $this->konto['user_id'];
                $wpdb->update($t, ['info' => wp_json_encode(['last_refresh_attempt' => time()])], ['account_id' => $id]);
                update_option('amendo_test_sbi_fornyet', (int) get_option('amendo_test_sbi_fornyet', 0) + 1, false);
                $svar = wp_remote_get('https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=' . $this->konto['access_token']);
                if (is_wp_error($svar) || wp_remote_retrieve_response_code($svar) !== 200) return false;
                $d = json_decode(wp_remote_retrieve_body($svar), true);
                if (empty($d['expires_in'])) return false;
                $wpdb->update($t, [
                    'access_token' => (new SB_Instagram_Data_Encryption())->encrypt($d['access_token']),
                    'expires'      => gmdate('Y-m-d H:i:s', time() + (int) $d['expires_in']),
                ], ['account_id' => $id]);
                return true;
            }
        }
    }
}
