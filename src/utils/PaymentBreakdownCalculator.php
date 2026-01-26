<?php
/**
 * Payment Breakdown Calculator
 * Calculates payment splits for orders
 */

class PaymentBreakdownCalculator {
    private $vendorInitialPct;
    private $insurancePct;
    private $commissionPct;
    private $vatPct;
    private $serviceCharge;
    private $insuranceSubaccount;
    
    public function __construct() {
        $this->vendorInitialPct = floatval(getenv('VENDOR_INITIAL_PERCENTAGE') ?: 50);
        $this->insurancePct = floatval(getenv('INSURANCE_PERCENTAGE') ?: 1);
        $this->commissionPct = floatval(getenv('COMMISSION_PERCENTAGE') ?: 5);
        $this->vatPct = floatval(getenv('VAT_PERCENTAGE') ?: 7.5);
        $this->serviceCharge = floatval(getenv('SERVICE_CHARGE') ?: 250);
        $this->insuranceSubaccount = getenv('INSURANCE_SUBACCOUNT_CODE') ?: '';
    }
    
    /**
     * Calculate payment breakdown for an order
     */
    public function calculate(array $orderData, string $paymentMethodType = 'individual'): array {
        $subtotal = floatval($orderData['subtotal'] ?? 0);
        $serviceCharge = floatval($orderData['service_charge'] ?? $this->serviceCharge);
        
        // Calculate VAT on (subtotal + service charge)
        $vatAmount = ($subtotal + $serviceCharge) * ($this->vatPct / 100);
        
        // Total amount customer pays
        $total = $subtotal + $serviceCharge + $vatAmount;
        
        // Calculate splits from subtotal
        $vendorInitialAmount = $subtotal * ($this->vendorInitialPct / 100);
        $insuranceAmount = $subtotal * ($this->insurancePct / 100);
        $commissionAmount = $subtotal * ($this->commissionPct / 100);
        
        // Balance (escrow) = subtotal - vendor initial - insurance - commission
        $vendorBalanceAmount = $subtotal - $vendorInitialAmount - $insuranceAmount - $commissionAmount;
        
        // Round all amounts to 2 decimal places
        $vendorInitialAmount = round($vendorInitialAmount, 2);
        $insuranceAmount = round($insuranceAmount, 2);
        $commissionAmount = round($commissionAmount, 2);
        $vendorBalanceAmount = round($vendorBalanceAmount, 2);
        $vatAmount = round($vatAmount, 2);
        $total = round($total, 2);
        
        return [
            'total' => $total,
            'subtotal' => $subtotal,
            'service_charge' => $serviceCharge,
            'vat_amount' => $vatAmount,
            'vat_pct' => $this->vatPct,
            'vendor_initial_pct' => $this->vendorInitialPct,
            'insurance_pct' => $this->insurancePct,
            'commission_pct' => $this->commissionPct,
            'vendor_initial_amount' => $vendorInitialAmount,
            'insurance_amount' => $insuranceAmount,
            'commission_amount' => $commissionAmount,
            'vendor_balance_amount' => $vendorBalanceAmount,
            'payment_method_type' => $paymentMethodType,
            'insurance_subaccount' => $this->insuranceSubaccount,
            'snapshot' => [
                'calculation_date' => date('Y-m-d H:i:s'),
                'formula' => [
                    'subtotal' => $subtotal,
                    'service_charge' => $serviceCharge,
                    'vat_formula' => "({$subtotal} + {$serviceCharge}) × ({$this->vatPct}%)",
                    'total_formula' => "{$subtotal} + {$serviceCharge} + {$vatAmount}",
                    'vendor_initial_formula' => "{$subtotal} × ({$this->vendorInitialPct}%)",
                    'insurance_formula' => "{$subtotal} × ({$this->insurancePct}%)",
                    'commission_formula' => "{$subtotal} × ({$this->commissionPct}%)",
                    'balance_formula' => "{$subtotal} - {$vendorInitialAmount} - {$insuranceAmount} - {$commissionAmount}",
                ],
            ],
        ];
    }
    
    /**
     * Convert amounts to smallest currency unit (kobo for NGN, cents for USD, etc.)
     */
    public function convertToSmallestUnit(float $amount, string $currencyCode): int {
        // Most currencies use 100 as the smallest unit
        // NGN = kobo (100), USD = cents (100), KES = cents (100)
        return intval($amount * 100);
    }
    
    /**
     * Convert from smallest currency unit back to main unit
     */
    public function convertFromSmallestUnit(int $amount, string $currencyCode): float {
        return floatval($amount / 100);
    }
}
