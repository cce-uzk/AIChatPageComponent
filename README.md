# AI-Chat PageComponent

### University of Cologne - ILIAS AI Integration

A ILIAS 9 PageComponent plugin that enables embedding AI-powered chat interfaces directly into learning pages. Each chat instance can be configured with custom system prompts for educational purposes, such as interactive exercises in AI literacy or subject-specific tutoring.

> **🎯 Modular Architecture**: This plugin features a **modular LLM service architecture** that supports multiple AI providers (RAMSES by OSKI.nrw, OpenAI, and easy integration of additional services). Organizations can configure their preferred AI service or add custom integrations.

## Screenshots

### Chat Interface in Action
![AI Chat Overview](docs/ChatOverview.jpg)

*AI chat interface showing conversation flow with multimodal capabilities*
 
### Embedded in ILIAS Pages
![Chat Page Embedded](docs/ChatPageEmbedded.jpg)
 
*PageComponent seamlessly integrated into ILIAS learning content*

### Administrative Configuration
![Chat Settings](docs/ChatSettings.jpg)
 
*Comprehensive configuration options for educators*

### File Upload & Preview
![Chat Upload Preview](docs/ChatUploadPreview.jpg)
 
*Multimodal file upload with image preview functionality*

## Features

### Core Functionality
- **Multiple AI Chats per Page**: Embed unlimited AI chat instances on a single ILIAS page
- **Custom System Prompts**: Configure each chat with specific educational contexts and roles
- **Multimodal Support**: Full support for text, images, and PDF document analysis
- **Background Files**: Upload context documents (text, images, PDFs) that inform AI responses
- **Session Management**: User-specific chat sessions with message history persistence
- **Export/Import Support**: Full ILIAS export/import compatibility with chat configurations and background files
- **Responsive UI**: Modern, accessible interface using ILIAS 9 UI components

### AI Service Integration
- **Modular LLM Architecture**: Easy integration of multiple AI service providers
- **Built-in Services**: Pre-configured support for RAMSES (OSKI.nrw) and OpenAI GPT
- **Service-Specific Configuration**: Independent settings per AI service (API endpoints, models, capabilities)
- **Dynamic Model Selection**: Automatic model discovery from AI service APIs
- **Flexible Routing**: Users can select AI service per chat instance (or enforce global default)

### Administration & Control
- **Global Configuration**: Administrator limits override local chat settings
- **Real-time Validation**: Limits with live feedback (character limits, upload limits)
- **Session Detection**: Automatic ILIAS session validation with user-friendly expiration handling
- **File Processing Pipeline**: Automatic image optimization, PDF-to-image conversion, multimodal AI integration
- **Production Ready**: Comprehensive logging, error handling, and security measures

## Requirements

### System Requirements
- **ILIAS**: 9.x
- **PHP**: 8.1 or higher
- **MySQL**: 8.0 or higher
- **Web Server**: Apache 2.4+ or Nginx 1.18+

### Dependencies
- **AI Service**: Compatible with OpenAI-compatible API endpoints (e.g., RAMSES/OSKI.nrw, OpenAI, custom deployments)
- **PHP Extensions**: `curl`, `gd`, `imagick` (recommended), `ghostscript` (for PDF processing)
- **ILIAS ResourceStorage**: For secure file handling (built-in ILIAS 9)

### Optional Dependencies
- **AIChat Repository Plugin**: Provides enhanced RBAC permission control for AI chat component creation. Without this plugin, all content editors can create AI chat components. Recommended for institutions requiring granular access control.

> **✅ Independent Plugin**: This plugin is **fully independent** and does **not require the AIChat base plugin**. All AI service integration is handled directly within this plugin.

## Permissions & Access Control

### Permission Strategy
This plugin implements a **two-tier permission system** to control who can add AI chat components to pages:

#### **Tier 1: Enhanced RBAC (Recommended)**
When the **AIChat Repository Plugin** is installed and active:
- Uses AIChat's `create_xaic` permission for access control
- Administrators can precisely control which users/roles can create AI chat components
- Provides granular permission management through ILIAS's standard role system

#### **Tier 2: Content Editor Fallback**
When AIChat plugin is **not available**:
- Falls back to basic `write` permission check
- Any user with content editing rights can add AI chat components
- Less granular but ensures functionality for content creators

### Technical Background
PageComponent plugins cannot integrate directly into ILIAS's standard RBAC system due to technical limitations (only Repository Object plugins support full RBAC integration). This plugin works around this limitation by leveraging the AIChat plugin's existing RBAC implementation when available.

### Security Considerations
- **Recommended Setup**: Install AIChat plugin even if not directly used, to enable fine-grained permission control
- **Fallback Risk**: Without AIChat plugin, all content editors can create AI chats - evaluate if this aligns with your institution's AI usage policies
- **Administrator Control**: Global configuration settings always override individual chat configurations regardless of permission level

## Installation

### 1. Download and Extract
1. Navigate to the root directory of your ILIAS installation
2. Run the following commands to clone the plugin repository:
```bash
mkdir -p Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent
cd Customizing/global/plugins/Services/COPage/PageComponent/
git clone https://github.com/cce-uzk/AIChatPageComponent.git ./AIChatPageComponent
cd AIChatPageComponent
git checkout main
```

### 2. Optional Plugin Dependencies
- **For enhanced RBAC control**: Install the AIChat Repository Plugin before this plugin to enable granular permission management
- **For basic functionality**: This plugin is self-contained and works without additional plugins (uses fallback permission system)

### 3. Install Composer Dependencies
Navigate to the root directory of your ILIAS installation:
```bash
composer du
npm install
php setup/setup.php update
```

### 4. Database Setup
The plugin automatically creates required database tables via `sql/dbupdate.php`:
- `pcaic_chats`: Chat configurations per PageComponent
- `pcaic_sessions`: User sessions per chat
- `pcaic_messages`: Messages bound to sessions
- `pcaic_attachments`: File attachments
- `pcaic_config`: Plugin-wide configuration settings

### 5. Install and Activate Plugin
In ILIAS Administration:
1. Navigate to **Administration > Extending ILIAS > Plugins**
2. Find **AIChatPageComponent** in the list
3. Click **Install**
4. Click **Activate**

## Configuration

### AI Service Setup
After plugin activation, configure your preferred AI service(s):

1. Navigate to **Administration > Extending ILIAS > Plugins**
2. Find **AIChatPageComponent** and click **Configure**

#### General Configuration Tab
- **Default AI Service**: Select which service to use by default for new chats
- **Force Default Service**: Optionally enforce the default service (prevents users from selecting other services)
- **Default Values**: Configure global defaults for chat instances
- **File Upload Constraints**: Set limits for file sizes and attachments

#### Service-Specific Configuration
Each AI service has its own configuration tab:

##### RAMSES (OSKI.nrw) Tab
- **Enable Service**: Activate RAMSES for use in chat instances
- **API Base URL**: Set the base API endpoint (default: `https://ramses-oski.itcc.uni-koeln.de`)
- **API Token**: Enter your API authentication token
- **Selected Model**: Choose from available models
- **Refresh Models**: Fetch latest models from API
- **Temperature**: Control response randomness (0.0-2.0)
- **Enable Streaming**: Real-time response streaming
- **RAG Configuration**: Upload files to RAG collections for efficient retrieval

##### OpenAI GPT Tab
- **Enable Service**: Activate OpenAI for use in chat instances
- **API Base URL**: Set the base API endpoint (default: `https://api.openai.com`)
- **API Token**: Enter your OpenAI API key
- **Selected Model**: Choose from available GPT models
- **Refresh Models**: Fetch latest models from OpenAI API
- **Temperature**: Control response randomness (0.0-2.0)
- **Enable Streaming**: Real-time response streaming

### System Requirements Check
Verify your system has required components:
```bash
# Check Ghostscript (for PDF processing)
gs --version

# Check ImageMagick
identify -version

# Check PHP extensions
php -m | grep -E "(curl|gd|imagick)"
```

## Usage

### For Content Creators

#### Adding AI Chats to Pages
1. Edit any ILIAS page (Course, Learning Module, Wiki, etc.)
2. Click **Insert > AI Chat**
3. Configure the chat:
   - **System Prompt**: Define the AI's role and behavior
   - **Background Files**: Upload context documents (optional)

#### Configuration Options
- **System Prompt**: Customize AI behavior and context
  ```
  You are a helpful tutor for organic chemistry. 
  Help students understand molecular structures and reactions.
  Always provide clear explanations with examples.
  ```
- **Background Files**: Upload supporting materials
  - Text files (.txt, .md, .csv): Added to AI context
  - Images (.jpg, .png, .gif, .webp): Visual analysis with automatic optimization
  - PDFs (.pdf): Converted to images for multimodal analysis (default up to 20 pages)
- **Memory Limit**: Number of previous messages to remember (default: 10)
- **Global Overrides**: Administrator settings take precedence over local configuration

### For Students

#### Using AI Chats
1. Navigate to any page containing AI chat components
2. Type messages in the chat interface with real-time character counter
3. Upload images or documents for AI analysis (drag & drop supported)
4. View conversation history with copy/download functionality
5. Each chat maintains separate user-specific session context
6. Automatic session validation with user-friendly expiration handling

#### File Upload Support
- **Images**: Direct multimodal analysis and discussion
- **PDFs**: Automatic page-by-page conversion (ghostscript) and AI analysis
- **Text Files**: Content integration into conversation context
- **File Persistence**: Uploads survive page reloads and session changes
- **Preview Support**: Image previews and download links for all file types

#### Export/Import
- **ILIAS Course Export**: AI chat components are automatically included in course exports
- **Configuration Preservation**: System prompts, settings, and background files are fully preserved
- **File Integrity**: Background files are exported and re-imported with proper ILIAS ResourceStorage integration
- **Independent Instances**: Imported chats create new, independent configurations (no shared data with originals)
- **Cross-Instance Compatibility**: Export from one ILIAS instance, import to another while maintaining full functionality

## Architecture

### Plugin Structure
```
AIChatPageComponent/
├── classes/                      # Core plugin classes
│   ├── ai/                      # AI service integrations (modular LLM architecture)
│   │   ├── class.AIChatPageComponentLLM.php              # Abstract base class
│   │   ├── class.AIChatPageComponentLLMRegistry.php      # Service registry
│   │   ├── class.AIChatPageComponentRAMSES.php          # RAMSES implementation
│   │   └── class.AIChatPageComponentOpenAI.php          # OpenAI implementation
│   ├── platform/                # Configuration bridge
│   └── class.*.php              # ILIAS integration classes
├── src/                         # Modern PHP 8+ classes
│   └── Model/                   # Database models (ChatConfig, ChatSession, ChatMessage, Attachment)
├── js/                          # Frontend JavaScript (ES6+ with comprehensive JSDoc)
├── css/                         # Modern CSS with ILIAS 9 UI integration
├── sql/                         # Database schema (dbupdate.php)
├── lang/                        # Translations (DE/EN)
└── vendor/                      # Composer dependencies
```

### Modular LLM Architecture

The plugin implements a **service registry pattern** for AI provider integration:

#### **Key Components**
- **`AIChatPageComponentLLM`**: Abstract base class defining the service contract
- **`AIChatPageComponentLLMRegistry`**: Central registry for service discovery and instantiation
- **Service Implementations**: Each AI service extends the base class with provider-specific logic

#### **Adding New Services**
New AI services are automatically integrated into:
- Configuration GUI (dynamic tab generation)
- Service selectors (admin and user-facing)
- API routing and message handling
- No core file modifications required!

See the **Development** section below for step-by-step integration guide.

### Database Architecture
The plugin uses the following table structure with clean separation of concerns:

#### **Core Tables**
- **`pcaic_chats`**: PageComponent configuration (system_prompt, background_files, AI service, limits)
- **`pcaic_sessions`**: User-specific chat sessions with automatic cleanup
- **`pcaic_messages`**: Conversation history bound to sessions
- **`pcaic_attachments`**: File attachments with ILIAS IRSS integration
- **`pcaic_config`**: Global plugin configuration (per-service settings, admin overrides)

#### **API Architecture v2.0**
- **Backend-Controlled**: All configuration managed server-side
- **Clean Frontend**: Only sends `chat_id` + `message` + `attachment_ids`
- **Session Management**: Automatic user session creation and management
- **Service Routing**: Dynamic LLM selection based on chat configuration

### AI Integration & File Processing

#### **Supported AI Services**
- **RAMSES (OSKI.nrw)** with RAG support
- **OpenAI GPT**
- **Custom Services**: Easy integration of additional providers

#### **Advanced File Processing Pipeline**
- **Text Files** (txt, md, csv): Direct content integration to system prompt
- **Images** (jpg, png, gif, webp): ILIAS Flavours compression → Base64 → Multimodal AI
- **PDFs**: Ghostscript page-wise conversion → PNG images → Base64 → Multimodal AI
- **Optimization**: Size limits (default: 15MB image data, 20 pages per PDF), automatic compression
- **Error Handling**: Fallbacks for failed conversions, graceful degradation

## Development

### Code Standards
- **PHP 8.1+** with strict types and modern features
- **PSR-4** autoloading via Composer
- **ILIAS 9** UI components and services
- **Comprehensive PHPDoc documentation** for all classes and methods
- **Comprehensive JSDoc documentation** for all JavaScript functions
- **Professional error handling** with session validation and user feedback
- **Comprehensive logging** with structured context

---

### 🚀 Adding New AI Services (LLM Providers)

The plugin's modular architecture makes it easy to integrate new AI providers. Follow these steps:

#### **Step 1: Create Service Class**

Create a new PHP class in `classes/ai/` extending `AIChatPageComponentLLM`:

```php
<?php declare(strict_types=1);

namespace ai;

/**
 * Example AI Service Implementation
 *
 * @author Your Name <your.email@example.com>
 */
class AIChatPageComponentExample extends AIChatPageComponentLLM
{
    // Service Metadata
    public static function getServiceId(): string
    {
        return 'example';
    }

    public static function getServiceName(): string
    {
        return 'Example AI';
    }

    public static function getServiceDescription(): string
    {
        return 'Example AI Service';
    }

    // Configuration Management
    public function getConfigurationFormInputs(): array
    {
        $ui_factory = $this->getDIC()->ui()->factory();
        $plugin = \ilAIChatPageComponentPlugin::getInstance();
        $inputs = [];

        // Enable Service Checkbox
        $enabled = \platform\AIChatPageComponentConfig::get('example_service_enabled') ?: '0';
        $inputs['example_service_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_service_enabled'),
            $plugin->txt('config_service_enabled_info')
        )->withValue($enabled === '1');

        // API URL
        $api_url = \platform\AIChatPageComponentConfig::get('example_api_url') ?: 'https://example.com';
        $inputs['example_api_url'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_api_url'),
            $plugin->txt('config_api_url_info')
        )->withValue($api_url);

        // API Token (Password field)
        $inputs['example_api_token'] = $ui_factory->input()->field()->password(
            $plugin->txt('config_api_token'),
            $plugin->txt('config_api_token_info')
        )->withRequired(true);

        // Model Selection (with cached models from API)
        $selected_model = \platform\AIChatPageComponentConfig::get('example_selected_model');
        $cached_models = \platform\AIChatPageComponentConfig::get('example_cached_models');

        if (is_array($cached_models) && !empty($cached_models)) {
            $model_options = $cached_models; // ['example-1-alpha' => 'Example 1 Alpha']

            $select_field = $ui_factory->input()->field()->select(
                $plugin->txt('config_selected_model'),
                $model_options,
                $plugin->txt('config_selected_model_info')
            )->withRequired(true);

            if ($selected_model && isset($model_options[$selected_model])) {
                $select_field = $select_field->withValue($selected_model);
            }

            $inputs['example_selected_model'] = $select_field;
        }

        // Add more service-specific fields as needed...

        return $inputs;
    }

    public function saveConfiguration(array $formData): void
    {
        foreach ($formData as $key => $value) {
            // Handle Password objects
            if ($value instanceof \ILIAS\Data\Password) {
                $value = $value->toString();
            }

            // Save to config
            \platform\AIChatPageComponentConfig::set($key, $value);
        }
    }

    public static function getDefaultConfiguration(): array
    {
        return [
            'example_service_enabled' => '1',
            'example_api_url' => 'https://example.com',
            'example_selected_model' => 'example-1-alpha',
            'example_streaming_enabled' => '1',
            'example_file_handling_enabled' => '1',
        ];
    }

    // Service Capabilities
    public function getCapabilities(): array
    {
        return [
            'streaming' => true,
            'rag' => false,  // Set to true if your service supports RAG
            'multimodal' => true,
            'file_types' => ['pdf'],
            'max_tokens' => 200000,
        ];
    }

    // Message Sending Implementation
    public function sendMessage(string $message, array $context = []): array
    {
        // Implement your service's API call here
        // Return format: ['content' => 'AI response', 'model' => 'model-id']

        $api_url = \platform\AIChatPageComponentConfig::get('example_api_url');
        $api_token = \platform\AIChatPageComponentConfig::get('example_api_token');
        $model = \platform\AIChatPageComponentConfig::get('example_selected_model');

        // Build request payload according to your API spec
        $payload = [
            'model' => $model,
            'messages' => $context,
            'max_tokens' => 4096,
        ];

        // Make API call (use your service's endpoint and format)
        $response = $this->makeApiCall($api_url . '/v1/messages', $api_token, $payload);

        return [
            'content' => $response['content'][0]['text'] ?? '',
            'model' => $model
        ];
    }

    // Optional: Implement streaming if supported
    public function sendMessageStreaming(string $message, array $context = []): void
    {
        // Implement Server-Sent Events (SSE) streaming
        // Similar to sendMessage but with chunk-by-chunk output
    }

    // Helper method for API calls
    private function makeApiCall(string $url, string $token, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $token,  // Adjust header based on your API
            'example-version: 2026-01-01'  // API version
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("API error: HTTP $httpCode");
        }

        return json_decode($response, true);
    }
}
```

#### **Step 2: Register Service**

Add your service to the registry in `classes/ai/class.AIChatPageComponentLLMRegistry.php`:

```php
public static function getAvailableServices(): array
{
    return [
        'ramses' => AIChatPageComponentRAMSES::class,
        'openai' => AIChatPageComponentOpenAI::class,
        'example' => AIChatPageComponentExample::class,  // <-- Add this line!
    ];
}
```

#### **Step 3: Update Composer Autoloader**

Regenerate the Composer autoloader from your ILIAS root:

```bash
composer dump-autoload
```

#### **Step 4: Add Language Variables** *(Optional)*

If you need service-specific language strings, add them to `lang/ilias_en.lang` and `lang/ilias_de.lang`:

```
example_service#:#Example AI Service
```

#### **That's It! ✅**

Your new AI service is now:
- ✅ Available in **Administration > Configuration > Example AI Tab**
- ✅ Selectable in **AI Service dropdowns** (General Config & Chat Settings)
- ✅ Automatically **routed** in `api.php` for message handling
- ✅ Fully **integrated** into the plugin ecosystem

#### **Testing Your Service**

1. Navigate to plugin configuration
2. Enable your new service in its configuration tab
3. Set it as the default AI service
4. Create a new chat component and send a message
5. Monitor logs for debugging: `/var/www/logs/ilias.log`

#### **Best Practices**

- **Error Handling**: Always catch and log API errors gracefully
- **Logging**: Use `$this->logger->info()` for debugging
- **Validation**: Validate all inputs and API responses
- **Configuration**: Follow the same patterns as existing services (RAMSES/OpenAI)
- **Capabilities**: Accurately report what your service supports
- **API Compatibility**: Use OpenAI-compatible formats when possible for easier integration

---

## Security

This plugin implements comprehensive security measures:
- **Input Validation**: All user inputs are validated and sanitized
- **File Upload Security**: MIME type validation and size limits
- **SQL Injection Prevention**: Prepared statements throughout
- **XSS Protection**: Proper output encoding
- **Access Control**: Integration with ILIAS permission system
- **Secure Logging**: Sensitive data excluded from logs

## Troubleshooting

### Common Issues

#### Plugin Not Visible
- Ensure AIChat plugin is installed and activated
- Check ILIAS permissions for PageComponent access
- Verify PHP version compatibility (8.1+)
- Ensure database tables were created via dbupdate.php

#### File Upload Failures
```bash
# Check PHP upload settings
php -i | grep -E "(upload_max_filesize|post_max_size|max_file_uploads)"

# Verify directory permissions
ls -la /var/www/html/ilias/data/
```

#### AI Response Errors
- Check OSKI.nrw endpoint connectivity
- Verify API token in plugin configuration
- Review ILIAS logs: `/var/www/logs/ilias.log`
- Check plugin debug logs for detailed error information
- Ensure selected model exists in OSKI.nrw API response

#### Session Expiration Issues
- Plugin automatically detects ILIAS session expiration
- Users receive user-friendly session expired messages
- No data loss - messages are preserved until proper logout

### Log Locations
- **ILIAS Component Log**: Component-specific logging (`comp.pcaic`)
- **Application Log**: ILIAS data directory logs
- **Debug Log**: Plugin directory `debug.log` (development)

## License

This project is licensed under the GPL-3.0 License - see the [LICENSE](LICENSE) file for details.

## Credits
- **Development**: University of Cologne, CompetenceCenter E-Learning
- **AI Service**: [OSKI.nrw](https://oski.nrw/) by Ruhr-University Bochum and University of Cologne

## Support

For support and questions:
- Create an issue in this repository
- Contact: [nadimo.staszak@uni-koeln.de]

---

## Customization for External Use

### Using with Existing AI Services

This plugin works out-of-the-box with:

1. **RAMSES (OSKI.nrw)**: Pre-configured RAMSES Service API
2. **OpenAI GPT**: Pre-configured for OpenAI Service API
3. **OpenAI-Compatible Services**: Any service with OpenAI-compatible endpoints

#### Configuration Steps
1. Navigate to plugin configuration
2. Select your AI service tab (RAMSES or OpenAI)
3. Enter your API endpoint and token
4. Test connection by refreshing models
5. Set as default service in General Configuration

### Integrating Custom AI Services

To add your organization's AI service:

1. **Check API Compatibility**: Ensure your service provides OpenAI-compatible endpoints (`/v1/chat/completions`, `/v1/models`)
2. **Create Service Class**: Follow the detailed guide in the **Development** section above
3. **Register Service**: Add to `LLMRegistry::getAvailableServices()`
4. **Configure**: Set API URL, token, and model selection
5. **Test**: Verify message sending and file handling work correctly

### Requirements for Custom Services

Your AI service should provide:
- **Chat Completions Endpoint**: `POST /v1/chat/completions`
  - Accept messages array in OpenAI format
  - Support system/user/assistant roles
  - Return JSON with `choices[0].message.content`
- **Models Endpoint**: `GET /v1/models` *(optional, for auto-discovery)*
  - Return array of available models
  - Format: `{data: [{id: 'model-id', name: 'Model Name'}]}`
- **Authentication**: Bearer token via `Authorization` header
- **Multimodal Support**: *(optional)* Accept `image_url` content types for file analysis

### File Processing Compatibility

- **Ghostscript**: Required for PDF processing (`gs --version`)
- **ImageMagick**: Recommended for image optimization (`identify -version`)
- **PHP Extensions**: Ensure `curl`, `gd`, and `imagick` are enabled

### Support & Community

For integration assistance:
- Review existing service implementations (RAMSES, OpenAI) as templates
- Check comprehensive code documentation in abstract base class
- Create issues for bugs or feature requests
- Contact: nadimo.staszak@uni-koeln.de
