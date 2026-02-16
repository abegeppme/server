<?php
/**
 * Cron Controller
 * Provides API endpoint for cron jobs (can be called via HTTP)
 * Protected by API key or secret token
 */

require_once __DIR__ . '/BaseController.php';

class CronController extends BaseController {
    
    public function index() {
        $this->sendResponse([
            'message' => 'Cron endpoints available',
            'endpoints' => [
                'POST /api/cron/48hour' => 'Process 48-hour hold period releases',
                'POST /api/cron/7day' => 'Process 7-day auto-releases',
            ],
            'note' => 'Requires CRON_SECRET in request header or query parameter',
        ]);
    }
    
    public function create() {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        $secret = $_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['secret'] ?? $_POST['secret'] ?? null;
        $expectedSecret = getenv('CRON_SECRET') ?: 'your-cron-secret-change-this';
        
        // Verify secret
        if ($secret !== $expectedSecret) {
            $this->sendError('Invalid cron secret', 403);
        }
        
        require_once __DIR__ . '/../services/HoldPeriodService.php';
        $service = new HoldPeriodService();
        
        if ($action === '48hour') {
            $result = $service->process48HourHoldReleases();
            $this->sendResponse([
                'success' => true,
                'message' => '48-hour hold periods processed',
                'result' => $result,
            ]);
        } elseif ($action === '7day') {
            $result = $service->process7DayAutoRelease();
            $this->sendResponse([
                'success' => true,
                'message' => '7-day auto-releases processed',
                'result' => $result,
            ]);
        } else {
            $this->sendError('Invalid action. Use "48hour" or "7day"', 400);
        }
    }
}
