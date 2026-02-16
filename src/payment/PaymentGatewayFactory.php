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
}
