<?php
declare(strict_types=1);

// Useful when script is being executed as a cron job.
$pathPrefix = '';

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

function logMessage(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function fail(string $message, int $exitCode = 1): never
{
    logMessage("ERROR: {$message}");
    exit($exitCode);
}

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Required environment variable {$name} is missing or empty.");
    }

    return $value;
}

try {

    // Load environment variables.
    $envFilename = empty($argv[1]) ? '.env' : $argv[1];

    $dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
    $dotenv->safeLoad();

    logMessage('OCI ARM capacity provisioner started.');

    // Validate required settings before passing them to strictly typed classes.
    $config = new OciConfig(
        requiredEnv('OCI_REGION'),
        requiredEnv('OCI_USER_ID'),
        requiredEnv('OCI_TENANCY_ID'),
        requiredEnv('OCI_KEY_FINGERPRINT'),
        requiredEnv('OCI_PRIVATE_KEY_FILENAME'),
        getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
        requiredEnv('OCI_SUBNET_ID'),
        requiredEnv('OCI_IMAGE_ID'),
        (int) getenv('OCI_OCPUS'),
        (int) getenv('OCI_MEMORY_IN_GBS')
    );

    // Configure boot volume.
    $bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
    $bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');

    if ($bootVolumeSizeInGBs !== '') {
        $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
    } elseif ($bootVolumeId !== '') {
        $config->setBootVolumeId($bootVolumeId);
    }

    // Initialize OCI API.
    $api = new OciApi();

    // Cache availability domains.
    if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
        $api->setCache(new FileCache($config));
    }

    // Configure rate-limit handling.
    $waitTime = (int) (getenv('TOO_MANY_REQUESTS_TIME_WAIT') ?: 600);

    if ($waitTime > 0) {
        $api->setWaiter(new TooManyRequestsWaiter($waitTime));
    }

    // Initialize Telegram notifier.
    $notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
        return new \Hitrov\Notification\Telegram();
    })();

    $shape = requiredEnv('OCI_SHAPE');
    $sshPublicKey = requiredEnv('OCI_SSH_PUBLIC_KEY');

    $maxRunningInstancesOfThatShape = 1;

    if (getenv('OCI_MAX_INSTANCES') !== false) {
        $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
    }

    // Check existing instances.
    logMessage('Checking existing instances...');

    $instances = $api->getInstances($config);

    $existingInstances = $api->checkExistingInstances(
        $config,
        $instances,
        $shape,
        $maxRunningInstancesOfThatShape
    );

    if ($existingInstances) {
        logMessage($existingInstances);
        logMessage('Instance limit reached. No provisioning required.');
        exit(0);
    }

    // Discover availability domains.
    logMessage('Discovering availability domains...');

    if (!empty($config->availabilityDomains)) {

        if (is_array($config->availabilityDomains)) {
            $availabilityDomains = $config->availabilityDomains;
        } else {
            $availabilityDomains = [$config->availabilityDomains];
        }

    } else {

        $availabilityDomains = $api->getAvailabilityDomains($config);

    }

    if (empty($availabilityDomains)) {
        fail('No availability domains found.');
    }

    logMessage(
        'Found ' . count($availabilityDomains) . ' availability domain(s).'
    );

    // Retry capacity checks without repeating the preliminary OCI GET requests.
    // Each round reuses the same instance list and availability-domain list.
    $maxCapacityAttempts = 3;

    for ($attempt = 1; $attempt <= $maxCapacityAttempts; $attempt++) {
        logMessage("Capacity attempt {$attempt} of {$maxCapacityAttempts}.");

        foreach ($availabilityDomains as $availabilityDomainEntity) {
            $availabilityDomain = is_array($availabilityDomainEntity)
                ? ($availabilityDomainEntity['name'] ?? '')
                : $availabilityDomainEntity;

            if ($availabilityDomain === '') {
                logMessage('WARNING: Skipping an availability-domain entry without a name.');
                continue;
            }

            logMessage("Attempting provisioning in: {$availabilityDomain}");

            try {
                $instanceDetails = $api->createInstance(
                    $config,
                    $shape,
                    $sshPublicKey,
                    $availabilityDomain
                );
            } catch (ApiCallException $e) {
                $message = $e->getMessage();
                logMessage("OCI API error: {$message}");

                // Retry only the specific host-capacity response. Other OCI errors
                // usually indicate a configuration, permission, or quota problem.
                $isCapacityError =
                    $e->getCode() === 500 &&
                    stripos($message, 'InternalError') !== false &&
                    stripos($message, 'Out of host capacity') !== false;

                if ($isCapacityError) {
                    logMessage("Capacity unavailable in {$availabilityDomain}.");
                    continue;
                }

                fail("OCI API request failed: {$message}");
            }

            $message = json_encode($instanceDetails, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($message === false) {
                $message = 'Instance created successfully, but JSON encoding failed.';
            }

            logMessage('SUCCESS: OCI instance created!');
            echo $message . PHP_EOL;

            // A notification failure must not turn a successful provisioning run
            // into a failed run that could trigger another OCI create request.
            try {
                if ($notifier->isSupported()) {
                    $notifier->notify($message);
                    logMessage('Telegram notification sent.');
                }
            } catch (Throwable $notificationError) {
                logMessage('WARNING: Telegram notification failed: ' . $notificationError->getMessage());
            }

            exit(0);
        }

        if ($attempt < $maxCapacityAttempts) {
            $retryWait = 30 * $attempt;
            logMessage("All availability domains are full. Waiting {$retryWait} seconds before the next capacity attempt.");
            sleep($retryWait);
        }
    }

    fail('CAPACITY_UNAVAILABLE: no capacity available after 3 attempts across the discovered availability domains.', 2);

} catch (Throwable $e) {

    fail(
        get_class($e) . ': ' . $e->getMessage()
    );

}
