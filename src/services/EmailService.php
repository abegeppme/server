<?php
/**
 * Email Service
 * Handles sending emails via SMTP or mail() function
 */

class EmailService {
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $fromEmail;
    private $fromName;
    private $useSMTP;
    
    public function __construct() {
        $this->smtpHost = getenv('SMTP_HOST') ?: 'localhost';
        $this->smtpPort = intval(getenv('SMTP_PORT') ?: 587);
        $this->smtpUser = getenv('SMTP_USER') ?: '';
        $this->smtpPass = getenv('SMTP_PASS') ?: '';
        $this->fromEmail = getenv('FROM_EMAIL') ?: 'noreply@abegeppme.com';
        $this->fromName = getenv('FROM_NAME') ?: 'AbegEppMe';
        $this->useSMTP = !empty($this->smtpUser);
    }
    
    /**
     * Send email
     */
    public function send(string $to, string $subject, string $body, bool $isHTML = true): bool {
        if ($this->useSMTP) {
            return $this->sendViaSMTP($to, $subject, $body, $isHTML);
        } else {
            return $this->sendViaMail($to, $subject, $body, $isHTML);
        }
    }
    
    /**
     * Send order confirmation email
     */
    public function sendOrderConfirmation(array $order, array $customer): bool {
        $subject = "Order Confirmation - {$order['order_number']}";
        $body = $this->renderTemplate('order_confirmation', [
            'order' => $order,
            'customer' => $customer,
        ]);
        return $this->send($customer['email'], $subject, $body);
    }
    
    /**
     * Send payment received email
     */
    public function sendPaymentReceived(array $payment, array $customer): bool {
        $subject = "Payment Received - {$payment['paystack_ref']}";
        $body = $this->renderTemplate('payment_received', [
            'payment' => $payment,
            'customer' => $customer,
        ]);
        return $this->send($customer['email'], $subject, $body);
    }
    
    /**
     * Send service completion notification
     */
    public function sendServiceComplete(array $order, array $customer): bool {
        $subject = "Service Completed - {$order['order_number']}";
        $body = $this->renderTemplate('service_complete', [
            'order' => $order,
            'customer' => $customer,
        ]);
        return $this->send($customer['email'], $subject, $body);
    }
    
    /**
     * Send via SMTP
     */
    private function sendViaSMTP(string $to, string $subject, string $body, bool $isHTML): bool {
        // Use PHPMailer or similar library
        // For now, fallback to mail()
        return $this->sendViaMail($to, $subject, $body, $isHTML);
    }
    
    /**
     * Send via PHP mail()
     */
    private function sendViaMail(string $to, string $subject, string $body, bool $isHTML): bool {
        $headers = [
            "From: {$this->fromName} <{$this->fromEmail}>",
            "Reply-To: {$this->fromEmail}",
            "X-Mailer: PHP/" . phpversion(),
        ];
        
        if ($isHTML) {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        }
        
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    /**
     * Render email template
     */
    private function renderTemplate(string $template, array $data): string {
        $templatePath = __DIR__ . "/../templates/emails/{$template}.php";
        
        if (file_exists($templatePath)) {
            ob_start();
            extract($data);
            include $templatePath;
            return ob_get_clean();
        }
        
        // Fallback simple template
        return $this->getDefaultTemplate($template, $data);
    }
    
    private function getDefaultTemplate(string $template, array $data): string {
        $templates = [
            'order_confirmation' => "
                <h2>Order Confirmation</h2>
                <p>Dear {$data['customer']['name']},</p>
                <p>Your order #{$data['order']['order_number']} has been confirmed.</p>
                <p>Total: {$data['order']['total']}</p>
            ",
            'payment_received' => "
                <h2>Payment Received</h2>
                <p>Dear {$data['customer']['name']},</p>
                <p>We have received your payment of {$data['payment']['amount']}.</p>
                <p>Reference: {$data['payment']['paystack_ref']}</p>
            ",
            'service_complete' => "
                <h2>Service Completed</h2>
                <p>Dear {$data['customer']['name']},</p>
                <p>The service for order #{$data['order']['order_number']} has been completed.</p>
                <p>Please confirm completion to release payment to the vendor.</p>
            ",
        ];
        
        return $templates[$template] ?? '<p>Notification from AbegEppMe</p>';
    }
}
