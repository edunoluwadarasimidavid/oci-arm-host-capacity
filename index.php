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

try {

    // Load environment variables.
    $envFilename = empty($argv[1]) ? '.env' : $argv[1];

    $dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
    $dotenv->safeLoad();

    logMessage('OCI ARM capacity provisioner started.');

    // Initialize OCI configuration.
    $config = new OciConfig(
        getenv('OCI_REGION'),
        getenv('OCI_USER_ID'),
        getenv('OCI_TENANCY_ID'),
        getenv('OCI_KEY_FINGERPRINT'),
        getenv('OCI_PRIVATE_KEY_FILENAME'),
        getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
        getenv('OCI_SUBNET_ID'),
        getenv('OCI_IMAGE_ID'),
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

    $shape = getenv('OCI_SHAPE');

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

    // Try each availability domain.
    foreach ($availabilityDomains as $availabilityDomainEntity) {

        $availabilityDomain = is_array($availabilityDomainEntity)
            ? $availabilityDomainEntity['name']
            : $availabilityDomainEntity;

        logMessage("Attempting provisioning in: {$availabilityDomain}");

        try {

            $instanceDetails = $api->createInstance(
                $config,
                $shape,
                getenv('OCI_SSH_PUBLIC_KEY'),
                $availabilityDomain
            );

        } catch (ApiCallException $e) {

            $message = $e->getMessage();

            logMessage("OCI API error: {$message}");

            // Detect Oracle host-capacity errors.
            $isCapacityError =
                $e->getCode() === 500 &&
                strpos($message, 'InternalError') !== false &&
                strpos($message, 'Out of host capacity') !== false;

            if ($isCapacityError) {

                logMessage(
                    "Capacity unavailable in {$availabilityDomain}."
                );

                // Try the next availability domain.
                sleep(16);

                continue;
            }

            // Other API errors indicate a configuration or API problem.
            fail("OCI API request failed: {$message}");

        }

        // Provisioning succeeded.
        $message = json_encode(
            $instanceDetails,
            JSON_PRETTY_PRINT
        );

        if ($message === false) {
            $message = 'Instance created successfully, but JSON encoding failed.';
        }

        logMessage('SUCCESS: OCI instance created!');
        echo $message . PHP_EOL;

        // Send Telegram notification.
        try {

            if ($notifier->isSupported()) {
                $notifier->notify($message);
                logMessage('Telegram notification sent.');
            }

        } catch (Throwable $notificationError) {

            logMessage(
                'WARNING: Telegram notification failed: ' .
                $notificationError->getMessage()
            );

        }

        exit(0);
    }

    // All availability domains exhausted.
    fail('No capacity available in any discovered availability domain.');

} catch (Throwable $e) {

    fail(
        get_class($e) . ': ' . $e->getMessage()
    );

}
