<?php
/**
 * Payment Gateway Factory
 * Creates appropriate gateway instance based on country
 */

require_once __DIR__ . '/PaymentGatewayInterface.php';
require_once __DIR__ . '/gateways/PaystackGateway.php';
require_once __DIR__ . '/gateways/FlutterwaveGateway.php';
require_once __DIR__ . '/gateways/PalmpayGateway.php';

class PaymentGatewayFactory {
    /**
     * Create payment gateway instance
     * @param string $gatewayName Gateway name (Paystack, Flutterwave, etc.)
     * @param array $config Gateway configuration
     * @return PaymentGatewayInterface
     */
    public static function create(string $gatewayName, array $config): PaymentGatewayInterface {
        $config = self::applyEnvFallbacks($gatewayName, $config);
        switch (strtolower($gatewayName)) {
            case 'paystack':
                return new PaystackGateway($config);
            case 'flutterwave':
                return new FlutterwaveGateway($config);
            case 'palmpay':
            case 'palm-pay':
                return new PalmpayGateway($config);
            default:
                throw new Exception("Unsupported payment gateway: $gatewayName");
        }
    }
    
    /**
     * Get gateway for country
     * @param string $countryId Country code (e.g., 'NG', 'KE')
     * @return PaymentGatewayInterface
     */
    public static function getGatewayForCountry(string $countryId): PaymentGatewayInterface {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT payment_gateway, payment_gateway_config 
            FROM countries 
            WHERE id = ? AND status = 'ACTIVE'
        ");
        $stmt->execute([$countryId]);
        $country = $stmt->fetch();
        
        if (!$country || !$country['payment_gateway']) {
            throw new Exception("No payment gateway configured for country: $countryId");
        }
        
        $config = json_decode($country['payment_gateway_config'] ?? '{}', true);
        
        return self::create($country['payment_gateway'], $config);
    }

    private static function applyEnvFallbacks(string $gatewayName, array $config): array {
        $name = strtolower(trim($gatewayName));

        if ($name === 'paystack') {
            if (empty($config['secret_key'])) {
                $config['secret_key'] = getenv('PAYSTACK_SECRET_KEY') ?: getenv('PAYSTACK_SECRET') ?: '';
            }
            if (empty($config['public_key'])) {
                $config['public_key'] = getenv('PAYSTACK_PUBLIC_KEY') ?: '';
            }
            if (empty($config['webhook_secret'])) {
                $config['webhook_secret'] = getenv('PAYSTACK_WEBHOOK_SECRET') ?: ($config['secret_key'] ?? '');
            }
            if (empty($config['base_url'])) {
                $config['base_url'] = 'https://api.paystack.co';
            }
            return $config;
        }

        if ($name === 'flutterwave') {
            if (empty($config['secret_key'])) {
                $config['secret_key'] = getenv('FLUTTERWAVE_SECRET_KEY') ?: getenv('FLW_SECRET_KEY') ?: '';
            }
            if (empty($config['public_key'])) {
                $config['public_key'] = getenv('FLUTTERWAVE_PUBLIC_KEY') ?: getenv('FLW_PUBLIC_KEY') ?: '';
            }
            if (empty($config['webhook_hash'])) {
                $config['webhook_hash'] = getenv('FLUTTERWAVE_WEBHOOK_HASH') ?: getenv('FLW_WEBHOOK_HASH') ?: '';
            }
            if (empty($config['base_url'])) {
                $config['base_url'] = 'https://api.flutterwave.com/v3';
            }
            return $config;
        }

        if ($name === 'palmpay' || $name === 'palm-pay') {
            if (empty($config['merchant_id'])) {
                $config['merchant_id'] = getenv('PALMPAY_MERCHANT_ID') ?: '';
            }
            if (empty($config['secret_key'])) {
                $config['secret_key'] = getenv('PALMPAY_SECRET_KEY') ?: '';
            }
            if (empty($config['webhook_secret'])) {
                $config['webhook_secret'] = getenv('PALMPAY_WEBHOOK_SECRET') ?: ($config['secret_key'] ?? '');
            }
            if (empty($config['base_url'])) {
                $config['base_url'] = 'https://openapi.palmpay.com';
            }
            return $config;
        }

        return $config;
    }
}
