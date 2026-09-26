<?php
/**
 * ServiceContainer - Provides access to all application services.
 */

require_once __DIR__ . '/ReferralService.php';
require_once __DIR__ . '/CommissionService.php';
require_once __DIR__ . '/SettlementService.php';
require_once __DIR__ . '/BillingService.php';
require_once __DIR__ . '/StaffIdentityService.php';

class ServiceContainer
{
    private static ?self $instance = null;
    private PDO $pdo;

    private ?ReferralService $referralService = null;
    private ?CommissionService $commissionService = null;
    private ?SettlementService $settlementService = null;
    private ?BillingService $billingService = null;
    private ?StaffIdentityService $staffIdentityService = null;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function getInstance(?PDO $pdo = null): self
    {
        if (self::$instance === null) {
            if ($pdo === null) {
                $pdo = db_connect();
            }
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    public function referrals(): ReferralService
    {
        if ($this->referralService === null) {
            $this->referralService = new ReferralService($this->pdo);
        }
        return $this->referralService;
    }

    public function commissions(): CommissionService
    {
        if ($this->commissionService === null) {
            $this->commissionService = new CommissionService($this->pdo);
        }
        return $this->commissionService;
    }

    public function settlements(): SettlementService
    {
        if ($this->settlementService === null) {
            $this->settlementService = new SettlementService($this->pdo);
        }
        return $this->settlementService;
    }

    public function billing(): BillingService
    {
        if ($this->billingService === null) {
            $this->billingService = new BillingService($this->pdo);
        }
        return $this->billingService;
    }

    public function staffIdentities(): StaffIdentityService
    {
        if ($this->staffIdentityService === null) {
            $this->staffIdentityService = new StaffIdentityService($this->pdo);
        }
        return $this->staffIdentityService;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}

/**
 * Convenience function to get services
 */
function services(): ServiceContainer
{
    return ServiceContainer::getInstance();
}
