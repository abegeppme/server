<?php
/**
 * Country Manager
 * Handles country-related operations
 */

class CountryManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get country by ID
     */
    public function getCountry(string $countryId): ?array {
        $stmt = $this->db->prepare("
            SELECT c.*, cur.code as currency_code, cur.symbol as currency_symbol
            FROM countries c
            INNER JOIN currencies cur ON c.currency_code = cur.code
            WHERE c.id = ? AND c.status = 'ACTIVE'
        ");
        $stmt->execute([$countryId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get all active countries
     */
    public function getAllCountries(): array {
        $stmt = $this->db->prepare("
            SELECT c.*, cur.code as currency_code, cur.symbol as currency_symbol
            FROM countries c
            INNER JOIN currencies cur ON c.currency_code = cur.code
            WHERE c.status = 'ACTIVE'
            ORDER BY c.name
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get states for country
     */
    public function getStates(string $countryId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM states 
            WHERE country_id = ? AND status = 'ACTIVE'
            ORDER BY name
        ");
        $stmt->execute([$countryId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get cities for state
     */
    public function getCities(string $stateId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM cities 
            WHERE state_id = ? AND status = 'ACTIVE'
            ORDER BY name
        ");
        $stmt->execute([$stateId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get exchange rate between currencies
     */
    public function getExchangeRate(string $fromCurrency, string $toCurrency): float {
        // This would typically fetch from an exchange rate API
        // For now, return 1.0 (same currency) or a default rate
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }
        
        // You can implement actual exchange rate fetching here
        // For example, using an API like exchangerate-api.com
        return 1.0; // Placeholder
    }
    
    /**
     * Convert currency amount
     */
    public function convertCurrency(float $amount, string $fromCurrency, string $toCurrency): ?float {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }
        
        $rate = $this->getExchangeRate($fromCurrency, $toCurrency);
        return $amount * $rate;
    }
}
