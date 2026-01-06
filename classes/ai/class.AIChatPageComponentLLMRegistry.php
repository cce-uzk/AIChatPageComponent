<?php declare(strict_types=1);

namespace ai;

/**
 * LLM Service Registry
 *
 * Central registry for all available LLM services.
 * Provides service discovery, registration, and instantiation.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentLLMRegistry
{
    /**
     * Get all available LLM services (registered in the system)
     *
     * To add a new service:
     * 1. Create a new class extending AIChatPageComponentLLM
     * 2. Add it to this array with a unique service ID
     * 3. The system will automatically:
     *    - Add a configuration tab in ConfigGUI
     *    - Add the service to AI Service selection
     *    - Handle service-specific settings
     *
     * @return array<string, class-string> Array of service_id => class_name
     */
    public static function getAvailableServices(): array
    {
        return [
            'ramses' => AIChatPageComponentRAMSES::class,
            'openai' => AIChatPageComponentOpenAI::class,
            // Add new services here - that's all you need to do!
        ];
    }

    /**
     * Get only enabled LLM services (based on configuration)
     *
     * @return array<string, class-string> Array of service_id => class_name
     */
    public static function getEnabledServices(): array
    {
        $available = self::getAvailableServices();
        $enabled = [];

        foreach ($available as $serviceId => $serviceClass) {
            $configKey = $serviceId . '_service_enabled';
            $isEnabled = \platform\AIChatPageComponentConfig::get($configKey);

            if ($isEnabled === '1') {
                $enabled[$serviceId] = $serviceClass;
            }
        }

        return $enabled;
    }

    /**
     * Get service class by service ID
     *
     * @param string $serviceId Service identifier (e.g., 'ramses', 'openai')
     * @return class-string|null Service class name or null if not found
     */
    public static function getServiceClass(string $serviceId): ?string
    {
        $services = self::getAvailableServices();
        return $services[$serviceId] ?? null;
    }

    /**
     * Check if a service is registered
     *
     * @param string $serviceId Service identifier
     * @return bool True if service exists
     */
    public static function serviceExists(string $serviceId): bool
    {
        return isset(self::getAvailableServices()[$serviceId]);
    }

    /**
     * Check if a service is enabled
     *
     * @param string $serviceId Service identifier
     * @return bool True if service is enabled
     */
    public static function isServiceEnabled(string $serviceId): bool
    {
        if (!self::serviceExists($serviceId)) {
            return false;
        }

        $configKey = $serviceId . '_service_enabled';
        $isEnabled = \platform\AIChatPageComponentConfig::get($configKey);

        return $isEnabled === '1';
    }

    /**
     * Create a service instance from config
     *
     * @param string $serviceId Service identifier
     * @return AIChatPageComponentLLM|null Service instance or null if not found
     */
    public static function createServiceInstance(string $serviceId): ?AIChatPageComponentLLM
    {
        $serviceClass = self::getServiceClass($serviceId);

        if ($serviceClass === null) {
            return null;
        }

        // Use static factory method if available
        if (method_exists($serviceClass, 'fromConfig')) {
            return $serviceClass::fromConfig();
        }

        // Fallback to constructor
        return new $serviceClass();
    }

    /**
     * Get service capabilities
     *
     * @param string $serviceId Service identifier
     * @return array Service capabilities or empty array if service not found
     */
    public static function getServiceCapabilities(string $serviceId): array
    {
        $instance = self::createServiceInstance($serviceId);

        if ($instance === null) {
            return [];
        }

        return $instance->getCapabilities();
    }

    /**
     * Get all services as options for select field (ID => Name)
     *
     * @param bool $onlyEnabled If true, only return enabled services
     * @return array<string, string> Array of service_id => service_name
     */
    public static function getServiceOptions(bool $onlyEnabled = false): array
    {
        $services = $onlyEnabled ? self::getEnabledServices() : self::getAvailableServices();
        $options = [];

        foreach ($services as $serviceId => $serviceClass) {
            $options[$serviceId] = $serviceClass::getServiceName();
        }

        return $options;
    }
}
