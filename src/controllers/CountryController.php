<?php
/**
 * Country and Location Controller
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../utils/CountryManager.php';

class CountryController extends BaseController {
    private $countryManager;
    
    public function __construct() {
        parent::__construct();
        $this->countryManager = new CountryManager();
    }
    
    public function index() {
        $countries = $this->countryManager->getAllCountries();
        $this->sendResponse($countries);
    }
    
    public function get($id) {
        if ($id === 'currencies') {
            $stmt = $this->db->prepare("
                SELECT * FROM currencies WHERE status = 'ACTIVE' ORDER BY name
            ");
            $stmt->execute();
            $currencies = $stmt->fetchAll();
            $this->sendResponse($currencies);
        } elseif (strlen($id) === 2) {
            // Country code
            $country = $this->countryManager->getCountry($id);
            if ($country) {
                $this->sendResponse($country);
            } else {
                $this->sendError('Country not found', 404);
            }
        } else {
            $this->sendError('Invalid country code', 400);
        }
    }
    
    public function getStates($country_id) {
        $states = $this->countryManager->getStates($country_id);
        $this->sendResponse($states);
    }
    
    public function getCities($country_id, $state_id = null) {
        if ($state_id) {
            $cities = $this->countryManager->getCities($state_id);
        } else {
            $cities = $this->countryManager->getCities(null, $country_id);
        }
        $this->sendResponse($cities);
    }
    
    public function postExchangeRate() {
        $data = $this->getRequestBody();
        $from = $data['from_currency'] ?? null;
        $to = $data['to_currency'] ?? null;
        $amount = $data['amount'] ?? 1;
        
        if (!$from || !$to) {
            $this->sendError('from_currency and to_currency are required', 400);
        }
        
        $converted = $this->countryManager->convertCurrency($amount, $from, $to);
        
        if ($converted === null) {
            $this->sendError('Exchange rate not available', 404);
        }
        
        $this->sendResponse([
            'from_currency' => $from,
            'to_currency' => $to,
            'amount' => $amount,
            'converted_amount' => $converted,
            'rate' => $this->countryManager->getExchangeRate($from, $to)
        ]);
    }
}
