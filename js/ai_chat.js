/**
 * AI Chat Page Component JavaScript
 * 
 * A comprehensive chat interface component for ILIAS Page Components that provides
 * AI-powered conversations with support for file attachments, markdown rendering,
 * streaming responses, and message persistence.
 * 
 * Features:
 * - Real-time AI chat with streaming responses
 * - File upload support (images, PDFs, text files)
 * - Markdown rendering with code syntax highlighting
 * - Message history persistence
 * - Responsive design
 * - Accessibility compliance
 * - Copy and regenerate functionality
 * 
 * @file ai_chat.js
 * @version 2.0.0
 * @author ILIAS AI Chat Team
 * @requires ILIAS 9.x
 * @since 1.0.0
 */

'use strict';

// ============================================================================
// Debug Configuration
// ============================================================================

/**
 * Production debug control - set to false for production deployment
 * @type {boolean}
 * @const
 */
const AICHAT_DEBUG = false;

/**
 * Debug logging function - disabled in production
 * @type {Function}
 * @const
 */
const debug = AICHAT_DEBUG ? console.log.bind(console) : () => {};

/**
 * Error logging function - disabled in production  
 * @type {Function}
 * @const
 */
const debugError = AICHAT_DEBUG ? console.error.bind(console) : () => {};

// ============================================================================
// Main AIChatPageComponent Class
// ============================================================================

/**
 * AI Chat Page Component
 * 
 * Main class that handles the AI chat interface, including message management,
 * file uploads, streaming responses, and user interactions.
 * 
 * @class AIChatPageComponent
 * @example
 * // Initialize a new chat component
 * const chat = new AIChatPageComponent('ai-chat-container-123');
 * 
 * @param {string} containerId - The ID of the HTML container element
 */
class AIChatPageComponent {
    /**
     * Creates an instance of AIChatPageComponent
     * 
     * @param {string} containerId - The ID of the HTML container element
     * @throws {Error} When container element is not found
     */
    constructor(containerId) {
        this.containerId = containerId;
        this.container = document.getElementById(containerId);
        this.isLoading = false;
        this.messageHistory = [];
        this.attachments = [];
        this.currentRequest = null;
        
        if (!this.container) {
            debugError('AIChatPageComponent: Container not found with ID:', containerId);
            return;
        }
        
        this.init();
    }
    
    /**
     * Initialize the chat component by setting up DOM elements and configuration
     * 
     * Sets up all necessary DOM references, extracts configuration from data attributes,
     * initializes event listeners, and loads chat history if persistence is enabled.
     * 
     * @private
     * @throws {Error} When required DOM elements are not found
     */
    init() {
        this.messagesArea = this.container.querySelector('.ai-chat-messages');
        this.inputArea = this.container.querySelector('.ai-chat-input');
        this.sendButton = this.container.querySelector('.ai-chat-send');
        this.welcomeMsg = this.container.querySelector('.ai-chat-welcome');
        this.loadingDiv = this.container.querySelector('.ai-chat-loading');
        this.srStatus = this.container.querySelector('.ai-chat-sr-status');
        
        // Initialize file upload DOM elements
        this.attachBtn = this.container.querySelector('.ai-chat-attach-btn');
        this.fileInput = this.container.querySelector('.ai-chat-file-input');
        this.attachmentsArea = this.container.querySelector('.ai-chat-attachments');
        
        // Create attachments area if it doesn't exist (template rendering issue fallback)
        // Note: enableChatUploads might not be set yet, so we check the data attribute directly
        const enableChatUploads = this.container.dataset.enableChatUploads === 'true';
        if (!this.attachmentsArea && enableChatUploads) {
            debug('AIChatPageComponent: Creating missing attachments area (fallback)');
            this.attachmentsArea = document.createElement('div');
            this.attachmentsArea.className = 'ai-chat-attachments';
            this.attachmentsArea.style.display = 'none';
            // Insert before the composer area
            const composerArea = this.container.querySelector('.ai-chat-composer');
            if (composerArea) {
                this.container.insertBefore(this.attachmentsArea, composerArea);
            }
        }
        
        this.attachmentsList = this.attachmentsArea; // Direct reference for thumbnails
        this.clearAttachmentsBtn = this.container.querySelector('.ai-chat-clear-attachments');
        
        // Debug DOM element initialization
        debug('AIChatPageComponent: DOM elements initialized', {
            container: !!this.container,
            attachBtn: !!this.attachBtn,
            fileInput: !!this.fileInput,
            attachmentsArea: !!this.attachmentsArea,
            attachmentsList: !!this.attachmentsList,
            clearAttachmentsBtn: !!this.clearAttachmentsBtn,
            attachmentsAreaCreated: this.container.querySelector('.ai-chat-attachments') !== null
        });
        this.charCounter = this.container.querySelector('.ai-chat-char-count');
        this.charLimitElement = this.container.querySelector('.ai-chat-char-limit');
        
        // Initialize clear chat functionality
        this.clearChatBtn = this.container.querySelector('.ai-chat-clear-btn');
        
        // Extract configuration from DOM data attributes
        this.chatId = this.container.dataset.chatId;
        this.apiUrl = this.container.dataset.apiUrl;
        this.systemPrompt = this.container.dataset.systemPrompt;
        this.maxMemory = parseInt(this.container.dataset.maxMemory) || 10;
        this.charLimit = parseInt(this.container.dataset.charLimit) || 2000;
        this.persistent = this.container.dataset.persistent === 'true';
        this.aiService = this.container.dataset.aiService || 'default';
        this.enableChatUploads = this.container.dataset.enableChatUploads === 'true';
        this.enableStreaming = this.container.dataset.enableStreaming === 'true';
        this.isAnonymous = this.container.dataset.isAnonymous === 'true';
        this.serviceUnavailable = this.container.dataset.serviceUnavailable === 'true';
        this.isAdmin = this.container.dataset.isAdmin === 'true';

        // In-memory conversation history for stateless anonymous sessions.
        // Never persisted to DB or localStorage; resets on every page load by design.
        this.anonymousHistory = [];

        // Initialize upload configuration (updated via API call)
        this.globalChatUploadsEnabled = true; // Default assumption until API check
        this.allowedFileTypes = []; // Populated from server configuration
        
        // Initialize localized UI text from data attributes
        this.lang = {
            copyMessageTitle: this.container.dataset.copyMessageTitle || 'Copy message',
            regenerateResponseTitle: this.container.dataset.regenerateResponseTitle || 'Regenerate response',
            likeResponseTitle: this.container.dataset.likeResponseTitle || 'Good response',
            dislikeResponseTitle: this.container.dataset.dislikeResponseTitle || 'Poor response',
            messageCopied: this.container.dataset.messageCopied || 'Copied!',
            messageCopyFailed: this.container.dataset.messageCopyFailed || 'Failed to copy',
            thinkingHeader: this.container.dataset.thinkingHeader || 'Thinking...',
            generationStopped: this.container.dataset.generationStopped || 'Generation stopped by user.',
            regenerateFailed: this.container.dataset.regenerateFailed || 'Failed to regenerate response. Please try again.',
            welcomeMessage: this.container.dataset.welcomeMessage || 'Start a conversation...',
            stopGeneration: this.container.dataset.stopGeneration || 'Stop generation',
            newMessageAria: this.container.dataset.newMessageAria || 'New message received',
            sourcesLabel: this.container.dataset.sourcesLabel || 'Quellen',
            pageLabel: this.container.dataset.pageLabel || 'Seite',
            pagesLabel: this.container.dataset.pagesLabel || 'Seiten'
        };
        
        // Initialize ILIAS page context integration
        this.pageId = parseInt(this.container.dataset.pageId) || 0;
        this.parentId = parseInt(this.container.dataset.parentId) || 0;
        this.parentType = this.container.dataset.parentType || '';
        this.includePageContext = this.container.dataset.includePageContext === 'true';
        this.backgroundFiles = this.container.dataset.backgroundFiles || '[]';
        
        debug('AIChatPageComponent: Initialized with config:', {
            chatId: this.chatId,
            apiUrl: this.apiUrl ? 'set' : 'not set',
            maxMemory: this.maxMemory,
            charLimit: this.charLimit,
            persistent: this.persistent,
            aiService: this.aiService
        });
        
        if (!this.sendButton || !this.inputArea) {
            debugError('AIChatPageComponent: Required elements not found');
            return;
        }
        
        this.bindEvents();
        this.initTheme();

        if (this.serviceUnavailable) {
            this.disableInputForUnavailableService();
        } else {
            this.checkUploadConfiguration();
        }

        this.loadChatHistory();
    }

    // ── Theme switching ──────────────────────────────────────────────────────

    static get THEME_STORAGE_KEY() { return 'ai_chat_theme'; }

    static get ICON_MOON() {
        return `<svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M6 .278a.768.768 0 0 1 .08.858 7.208 7.208 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277.527 0 1.04-.055 1.533-.16a.787.787 0 0 1 .81.316.733.733 0 0 1-.031.893A8.349 8.349 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.752.752 0 0 1 6 .278z"/>
        </svg>`;
    }

    static get ICON_SUN() {
        return `<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm0 1a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 13zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193 2.121a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 0 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707z"/>
        </svg>`;
    }

    initTheme() {
        const saved = localStorage.getItem(AIChatPageComponent.THEME_STORAGE_KEY);
        let theme;
        if (saved === 'light' || saved === 'dark') {
            theme = saved;
        } else if (window.matchMedia('(prefers-color-scheme: light)').matches) {
            theme = 'light';
        } else {
            theme = 'dark';
        }
        this.container.dataset.theme = theme;
        this.updateThemeButton();
    }

    toggleTheme() {
        const next = this.container.dataset.theme === 'light' ? 'dark' : 'light';
        this.container.dataset.theme = next;
        localStorage.setItem(AIChatPageComponent.THEME_STORAGE_KEY, next);
        this.updateThemeButton();
    }

    updateThemeButton() {
        const btn = this.container.querySelector('.ai-chat-theme-toggle');
        if (!btn) return;
        const isDark = this.container.dataset.theme === 'dark';
        // Show sun when dark (clicking switches to light), moon when light (clicking switches to dark)
        btn.innerHTML = isDark ? AIChatPageComponent.ICON_SUN : AIChatPageComponent.ICON_MOON;
        btn.title = isDark
            ? (btn.dataset.titleLight || btn.title)
            : (btn.dataset.titleDark || btn.title);
    }
    
    /**
     * Check global configuration and apply administrator overrides
     * 
     * Fetches global administrator settings and applies limits that override
     * local PageComponent settings. Global limits take precedence over local
     * settings to ensure compliance with administrator policies.
     * 
     * @async
     * @private
     * @returns {Promise<void>}
     * @throws {Error} When API request fails
     */
    async checkUploadConfiguration() {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'get_global_config',
                    chat_id: this.chatId,
                    config_type: 'all'
                })
            });
            
            if (!response.ok) {
                debugError('AIChatPageComponent: Upload config check failed');
                return;
            }
            
            const data = await response.json();
            
            debug('AIChatPageComponent: Global config API response', {
                success: data.success,
                upload_enabled: data.upload_enabled,
                max_char_limit: data.max_char_limit,
                max_memory_limit: data.max_memory_limit,
                allowed_extensions: data.allowed_extensions?.length || 0,
                rawData: data
            });
            
            if (data.success) {
                // Apply global upload configuration
                this.globalChatUploadsEnabled = data.upload_enabled;

                // Use pre-computed accept values from server (includes both MIME types and extensions)
                // This ensures consistency between editor and chat uploads
                this.allowedAcceptValues = data.allowed_accept_values || [];

                // Also store individual arrays for validation
                this.allowedFileTypes = data.allowed_mime_types || [];
                this.allowedExtensions = data.allowed_extensions || [];

                debug('AIChatPageComponent: Processed allowed file types:', {
                    acceptValues: this.allowedAcceptValues,
                    mimeTypes: this.allowedFileTypes,
                    extensions: this.allowedExtensions
                });

                // Apply global limits that override local PageComponent settings
                this.applyGlobalLimits(data);

                // Update file input restrictions based on server configuration
                this.updateFileInputAcceptAttribute();
                
                // Determine effective upload state (both page and global settings must allow)
                const effectiveUploadsEnabled = this.enableChatUploads && this.globalChatUploadsEnabled;
                
                if (!effectiveUploadsEnabled && this.enableChatUploads) {
                    // Global admin settings override page component settings
                    this.hideFileUploadElements();
                    debug('AIChatPageComponent: Chat uploads disabled by global administrator settings');
                }
                
                debug('AIChatPageComponent: Global configuration loaded', {
                    finalCharLimit: this.charLimit,
                    finalMaxMemory: this.maxMemory,
                    globalConfigReceived: {
                        max_char_limit: data.max_char_limit,
                        max_memory_limit: data.max_memory_limit
                    }
                });
                
                debug('AIChatPageComponent: Upload configuration applied', {
                    pageComponentEnabled: this.enableChatUploads,
                    globalEnabled: this.globalChatUploadsEnabled,
                    effectiveEnabled: effectiveUploadsEnabled,
                    allowedTypes: this.allowedFileTypes,
                    allowedExtensions: this.allowedExtensions
                });
            }
        } catch (error) {
            debugError('AIChatPageComponent: Error checking global configuration:', error);
        }
    }
    
    /**
     * Apply global administrator limits that override local PageComponent settings
     * 
     * Global limits take precedence over local settings to ensure system-wide
     * compliance with administrator policies. This includes character limits,
     * memory limits, and other configurable restrictions.
     * 
     * @private
     * @param {Object} globalConfig - Global configuration data from server
     */
    applyGlobalLimits(globalConfig) {
        const originalCharLimit = this.charLimit;
        const originalMaxMemory = this.maxMemory;
        
        debug('AIChatPageComponent: Applying global limits', {
            currentCharLimit: this.charLimit,
            currentMaxMemory: this.maxMemory,
            globalCharLimit: globalConfig.max_char_limit,
            globalMemoryLimit: globalConfig.max_memory_limit
        });
        
        // Apply global character limit if it's more restrictive than local setting
        debug('AIChatPageComponent: Checking global character limit', {
            hasGlobalLimit: !!globalConfig.max_char_limit,
            globalLimitValue: globalConfig.max_char_limit,
            globalLimitGreaterZero: globalConfig.max_char_limit > 0,
            localLimit: this.charLimit,
            shouldApply: globalConfig.max_char_limit && globalConfig.max_char_limit > 0 && this.charLimit > globalConfig.max_char_limit
        });
        
        if (globalConfig.max_char_limit && globalConfig.max_char_limit > 0) {
            if (this.charLimit > globalConfig.max_char_limit) {
                this.charLimit = globalConfig.max_char_limit;
                debug('AIChatPageComponent: Character limit REDUCED by global admin setting', {
                    original: originalCharLimit,
                    enforced: this.charLimit,
                    reason: 'Global administrator limit override'
                });
                
                // Update UI display if char limit element exists
                if (this.charLimitElement) {
                    debug('AIChatPageComponent: Updating character limit display', {
                        element: this.charLimitElement,
                        newValue: this.charLimit
                    });
                    this.charLimitElement.textContent = this.charLimit;
                } else {
                    debug('AIChatPageComponent: Character limit element not found!');
                }
            } else {
                debug('AIChatPageComponent: Local character limit is within global limit, no change needed', {
                    local: this.charLimit,
                    global: globalConfig.max_char_limit
                });
            }
        } else {
            debug('AIChatPageComponent: No global character limit set or limit is 0');
        }
        
        // Apply global memory limit if it's more restrictive than local setting
        if (globalConfig.max_memory_limit && globalConfig.max_memory_limit > 0) {
            if (this.maxMemory > globalConfig.max_memory_limit) {
                this.maxMemory = globalConfig.max_memory_limit;
                debug('AIChatPageComponent: Memory limit reduced by global admin setting', {
                    original: originalMaxMemory,
                    enforced: this.maxMemory,
                    reason: 'Global administrator limit override'
                });
            }
        }
        
        // Log and optionally notify user if any limits were applied
        if (this.charLimit !== originalCharLimit || this.maxMemory !== originalMaxMemory) {
            debug('AIChatPageComponent: Global administrator limits applied', {
                charLimit: {
                    original: originalCharLimit,
                    enforced: this.charLimit,
                    overridden: this.charLimit !== originalCharLimit
                },
                maxMemory: {
                    original: originalMaxMemory,
                    enforced: this.maxMemory,
                    overridden: this.maxMemory !== originalMaxMemory
                }
            });
            
            // Show a subtle info message about global limits (only if enabled in config)
            if (this.container.dataset.showGlobalLimitInfo === 'true') {
                const limitInfo = [];
                if (this.charLimit !== originalCharLimit) {
                    limitInfo.push(`Character limit: ${this.charLimit}`);
                }
                if (this.maxMemory !== originalMaxMemory) {
                    limitInfo.push(`Memory limit: ${this.maxMemory} messages`);
                }
                
                if (limitInfo.length > 0) {
                    this.showGlobalLimitInfo(limitInfo.join(', '));
                }
            }
        } else {
            debug('AIChatPageComponent: Local settings within global limits, no overrides needed');
        }
    }
    
    /**
     * Show subtle information about applied global limits
     * 
     * Displays a temporary, non-intrusive message informing users that
     * administrator-configured limits have been applied to their chat.
     * 
     * @private
     * @param {string} limitInfo - Description of applied limits
     */
    showGlobalLimitInfo(limitInfo) {
        const infoDiv = document.createElement('div');
        infoDiv.className = 'ai-chat-global-limit-info';
        infoDiv.style.cssText = `
            position: absolute;
            top: -30px;
            right: 0;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 0.8em;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        `;
        
        infoDiv.textContent = `Administrator limits applied: ${limitInfo}`;
        
        // Position relative to chat container
        this.container.style.position = 'relative';
        this.container.appendChild(infoDiv);
        
        // Fade in
        setTimeout(() => {
            infoDiv.style.opacity = '1';
        }, 100);
        
        // Fade out and remove after 5 seconds
        setTimeout(() => {
            infoDiv.style.opacity = '0';
            setTimeout(() => {
                if (infoDiv.parentNode) {
                    infoDiv.parentNode.removeChild(infoDiv);
                }
            }, 300);
        }, 5000);
    }
    
    /**
     * Bind all event listeners for user interactions
     * 
     * Sets up event listeners for:
     * - Send button clicks (message sending or generation stopping)
     * - Enter key presses in textarea
     * - Input changes for character counting and textarea resizing
     * - File upload button clicks and file selection
     * - Clear attachments and clear chat functionality
     * 
     * Conditionally binds file upload events based on upload configuration.
     * 
     * @private
     */
    bindEvents() {
        // Bind primary interaction events
        this.sendButton.addEventListener('click', (e) => {
            e.preventDefault();
            if (this.isLoading) {
                this.stopGeneration();
            } else {
                this.sendMessage();
            }
        });
        
        // Handle Enter key for message sending (Shift+Enter for new line)
        this.inputArea.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });
        
        // Handle input changes for UI updates
        this.inputArea.addEventListener('input', (e) => {
            this.updateCharacterCounter();
            this.resizeComposer();
        });
        
        // Initialize file upload event handlers (if enabled by configuration)
        if (this.enableChatUploads) {
            if (this.attachBtn) {
                this.attachBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.fileInput.click();
                });
            }
            
            if (this.fileInput) {
                this.fileInput.addEventListener('change', (e) => {
                    this.handleFileSelection(e.target.files);
                });
            }
            
            if (this.clearAttachmentsBtn) {
                this.clearAttachmentsBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.clearAttachments();
                });
            }
        } else {
            // Hide upload UI when feature is disabled
            this.hideFileUploadElements();
        }
        
        // Initialize clear chat functionality
        if (this.clearChatBtn) {
            debug('AIChatPageComponent: Clear chat button found, adding event listener');
            this.clearChatBtn.addEventListener('click', (e) => {
                debug('AIChatPageComponent: Clear chat button clicked');
                e.preventDefault();
                this.clearChatHistory();
            });
        } else {
            debug('AIChatPageComponent: Clear chat button not found with selector .ai-chat-clear-btn');
        }

        const themeToggle = this.container.querySelector('.ai-chat-theme-toggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleTheme();
            });
        }
    }
    
    /**
     * Send a message to the AI service
     * 
     * Validates the message, adds it to the chat history, shows loading state,
     * and makes an API request. Handles both streaming and non-streaming responses.
     * Includes file attachments if any are selected.
     * 
     * @public
     * @async
     * @returns {Promise<void>}
     * @throws {Error} When API request fails or response is invalid
     */
    sendMessage() {
        if (this.serviceUnavailable) {
            return;
        }

        // Prevent multiple concurrent requests
        if (this.isLoading) {
            debug('AIChatPageComponent: Request already in progress, skipping');
            return;
        }
        
        // Validate message content
        const message = this.inputArea.value.trim();
        if (!message) {
            this.inputArea.focus();
            return;
        }
        
        // Validate message length (using potentially overridden global limit)
        if (message.length > this.charLimit) {
            const errorMsg = this.container.dataset.errorMessageTooLong || 
                `Message too long. Maximum ${this.charLimit} characters allowed.`;
            this.showAlert(errorMsg);
            return;
        }
        
        // Validate API configuration before sending
        if (!this.apiUrl) {
            this.showAlert('AI service is not configured. Please contact your administrator.');
            return;
        }
        
        if (!this.chatId) {
            this.showAlert('Chat configuration error. Please refresh the page and try again.');
            return;
        }
        
        // Check session validity before sending
        if (!this.isSessionValid()) {
            this.handleSessionExpired();
            return;
        }
        
        // Store attachments before clearing them from input area
        const currentAttachments = [...this.attachments];
        
        // Remove welcome message if it exists
        const welcomeMsg = this.container.querySelector('.ai-chat-welcome');
        if (welcomeMsg) {
            welcomeMsg.remove();
        }
        
        // Add user message to display (with attachments if any)
        this.addMessageToDisplay('user', message, currentAttachments);
        this.inputArea.value = '';
        
        // Reset composer size and state after sending
        this.resizeComposer();
        this.updateCharacterCounter(); // Hide character counter since input is now empty
        
        // Clear attachments from input area immediately
        this.clearAttachments();
        
        // Show loading
        this.setLoading(true);
        
        // Send message to AI (with attachments if any)
        if (currentAttachments.length > 0) {
            if (this.enableStreaming) {
                this.sendMessageToAIStream(message, currentAttachments);
            } else {
                this.sendMessageWithFiles(message, currentAttachments);
            }
        } else {
            if (this.enableStreaming) {
                this.sendMessageToAIStream(message);
            } else {
                this.sendMessageToAI(message);
            }
        }
    }
    
    /**
     * Send message to AI service using non-streaming HTTP request
     * 
     * Makes a standard HTTP POST request to the AI service API and processes
     * the complete response. Used when streaming is disabled or not supported.
     * 
     * @async
     * @private
     * @param {string} message - The user message to send
     * @returns {Promise<void>}
     * @throws {Error} When API request fails or returns invalid response
     */
    async sendMessageToAI(message) {
        try {
            // Check if API is available
            if (!this.apiUrl || this.apiUrl === '') {
                throw new Error('API URL not configured. Please ensure the plugin is properly installed.');
            }
            
            debug('AIChatPageComponent: Sending message to API:', message);
            debug('AIChatPageComponent: API URL:', this.apiUrl);
            
            // Create abort controller for this request
            this.currentRequest = new AbortController();
            
            // Send message
            const requestBody = {
                action: 'send_message',
                chat_id: this.chatId,
                message: message
            };

            if (this.isAnonymous) {
                requestBody.conversation_history = this.anonymousHistory;
            }

            // Send the message to AIChatPageComponent API v2.0
            debug('AIChatPageComponent: About to fetch:', this.apiUrl);
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestBody),
                signal: this.currentRequest.signal
            });
            
            debug('AIChatPageComponent: Response status:', response.status);
            debug('AIChatPageComponent: Response headers:', response.headers);
            
            if (!response.ok) {
                // Check for session expiration (redirect to login)
                if (response.status === 302 || response.status === 401) {
                    debug('AIChatPageComponent: Session expired (HTTP ' + response.status + ')');
                    this.handleSessionExpired();
                    return; // Don't throw error, already handled
                }

                // Try to get user-friendly error from response body
                const errorText = await response.text();
                debugError('HTTP Error Response:', errorText);
                let errorMessage = 'Communication with server failed';
                let serverProvided = false;
                try {
                    const errorData = JSON.parse(errorText);
                    if (errorData.error) {
                        errorMessage = errorData.error;
                        serverProvided = true;
                    }
                } catch (e) {
                    // Log technical details for debugging but don't expose to user
                    debugError('Response parsing failed:', e);
                }
                // Server-provided messages (rate limit, access denied, …) are shown as-is
                if (serverProvided || response.status === 429) {
                    this.currentRequest = null;
                    this.setLoading(false);
                    if (errorMessage === 'no_service_available') {
                        const notice = this.container.dataset.noServiceAvailable
                            || 'No AI service is currently available.';
                        this.addMessageToDisplay('system', notice);
                        this.disableInputForUnavailableService(false);
                    } else {
                        this.addMessageToDisplay('system', errorMessage);
                    }
                    return;
                }
                throw new Error(errorMessage);
            }

            const responseText = await response.text();
            debug('API Response:', responseText);

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                debugError('Failed to parse JSON:', responseText);
                throw new Error('Invalid JSON response: ' + responseText.substring(0, 100));
            }

            if (!data.success) {
                throw new Error(data.error || 'Request failed');
            }

            // Extract AI response from ILIAS API format
            const aiResponse = data.message;
            const sources = data.sources || null;
            const usage = data.usage || null;

            if (aiResponse) {
                if (this.isAnonymous) {
                    this.anonymousHistory.push({role: 'user', message: message});
                    this.anonymousHistory.push({role: 'assistant', message: aiResponse});
                }
                this.addMessageToDisplay('assistant', aiResponse, [], sources, usage);
            } else {
                debugError('AIChatPageComponent: Unexpected response structure:', data);
                throw new Error('No AI response received');
            }

            this.currentRequest = null;
            this.setLoading(false);
            this.saveChatHistory();

        } catch (error) {
            // Handle user-initiated request cancellation gracefully
            if (error.name === 'AbortError') {
                debug('AIChatPageComponent: Request was aborted by user');
                return;
            }
            
            // Log technical details for debugging
            debugError('AIChatPageComponent: API request failed:', {
                error: error.message,
                apiUrl: this.apiUrl,
                chatId: this.chatId
            });
            
            // Clean up request state
            this.currentRequest = null;
            this.setLoading(false);
            
            // Show user-friendly error message
            const userMessage = this.getErrorMessage(error);
            this.addMessageToDisplay('system', userMessage);
        }
    }
    
    /**
     * Send message with file attachments to AI service
     * 
     * Similar to sendMessageToAI but includes file attachment IDs in the request.
     * The server will process and include the files in the AI context.
     * 
     * @async
     * @private
     * @param {string} message - The user message to send
     * @param {Array<Object>} [attachments=null] - Array of attachment objects with id property
     * @returns {Promise<void>}
     * @throws {Error} When API request fails or returns invalid response
     */
    async sendMessageWithFiles(message, attachments = null) {
        try {
            if (!this.apiUrl || this.apiUrl === '') {
                throw new Error('API URL not configured. Please ensure the plugin is properly installed.');
            }
            
            debug('AIChatPageComponent: Sending message with files to API:', message);
            
            // Create abort controller for this request
            this.currentRequest = new AbortController();
            
            // Send message with attachments
            const requestBody = {
                action: 'send_message',
                chat_id: this.chatId,
                message: message,
                attachment_ids: (attachments || this.attachments).map(att => att.id)
            };

            if (this.isAnonymous) {
                requestBody.conversation_history = this.anonymousHistory;
            }
            
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestBody),
                signal: this.currentRequest.signal
            });
            
            if (!response.ok) {
                // Check for session expiration (redirect to login)
                if (response.status === 302 || response.status === 401) {
                    debug('AIChatPageComponent: Session expired in file upload (HTTP ' + response.status + ')');
                    this.handleSessionExpired();
                    return; // Don't throw error, already handled
                }
                
                // Try to get user-friendly error from response body
                const errorText = await response.text();
                debugError('HTTP Error Response:', errorText);
                let errorMessage = 'Communication with server failed';
                let serverProvided2 = false;
                try {
                    const errorData = JSON.parse(errorText);
                    if (errorData.error) {
                        errorMessage = errorData.error;
                        serverProvided2 = true;
                    }
                } catch (e) {
                    debugError('Response parsing failed:', e);
                }
                if (serverProvided2 || response.status === 429) {
                    this.currentRequest = null;
                    this.setLoading(false);
                    if (errorMessage === 'no_service_available') {
                        const notice = this.container.dataset.noServiceAvailable
                            || 'No AI service is currently available.';
                        this.addMessageToDisplay('system', notice);
                        this.disableInputForUnavailableService(false);
                    } else {
                        this.addMessageToDisplay('system', errorMessage);
                    }
                    return;
                }
                throw new Error(errorMessage);
            }

            const responseText = await response.text();
            debug('API Response:', responseText);

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                debugError('Failed to parse JSON:', responseText);
                throw new Error('Invalid JSON response: ' + responseText.substring(0, 100));
            }

            if (!data.success) {
                throw new Error(data.error || 'Request failed');
            }

            const aiResponse = data.message;

            if (aiResponse) {
                if (this.isAnonymous) {
                    this.anonymousHistory.push({role: 'user', message: message});
                    this.anonymousHistory.push({role: 'assistant', message: aiResponse});
                }
                this.addMessageToDisplay('assistant', aiResponse);
            } else {
                debugError('AIChatPageComponent: Unexpected response structure:', data);
                throw new Error('No AI response received');
            }

            this.currentRequest = null;
            this.setLoading(false);
            this.saveChatHistory();

        } catch (error) {
            // Handle user-initiated request cancellation gracefully
            if (error.name === 'AbortError') {
                debug('AIChatPageComponent: File upload request was aborted by user');
                return;
            }
            
            // Log technical details for debugging
            debugError('AIChatPageComponent: File upload API request failed:', {
                error: error.message,
                apiUrl: this.apiUrl,
                chatId: this.chatId,
                attachmentCount: this.attachments.length
            });
            
            // Clean up request state
            this.currentRequest = null;
            this.setLoading(false);
            
            // Show user-friendly error message
            const userMessage = this.getErrorMessage(error);
            this.addMessageToDisplay('system', userMessage);
        }
    }
    
    /**
     * Send message to AI service using Server-Sent Events streaming
     * 
     * Uses EventSource to establish a streaming connection with the server,
     * receiving AI response chunks in real-time. Provides better user experience
     * for long responses by showing partial results as they arrive.
     * 
     * @async
     * @private
     * @param {string} message - The user message to send
     * @param {Array<Object>} [attachments=null] - Array of attachment objects with id property
     * @returns {Promise<void>}
     * @throws {Error} When streaming connection fails or receives error response
     */
    async sendMessageToAIStream(message, attachments = null) {
        try {
            if (!this.apiUrl || this.apiUrl === '') {
                throw new Error('API URL not configured. Please ensure the plugin is properly installed.');
            }
            
            debug('AIChatPageComponent: Starting streaming message to API:', message);
            
            // Create request body
            const requestBody = {
                action: 'send_message_stream',
                chat_id: this.chatId,
                message: message
            };
            
            // Add attachments if provided
            if (attachments && attachments.length > 0) {
                requestBody.attachment_ids = attachments.map(att => att.id);
            }
            
            if (this.isAnonymous) {
                requestBody.conversation_history = this.anonymousHistory;
            }

            // EventSource only supports GET, so we need to pass data as URL parameters
            const params = new URLSearchParams();
            params.append('action', requestBody.action);
            params.append('chat_id', requestBody.chat_id);
            params.append('message', requestBody.message);
            if (requestBody.attachment_ids) {
                params.append('attachment_ids', JSON.stringify(requestBody.attachment_ids));
            }
            if (requestBody.conversation_history) {
                params.append('conversation_history', JSON.stringify(requestBody.conversation_history));
            }
            
            const eventSource = new EventSource(this.apiUrl + '?' + params.toString());
            
            // Store reference for abort capability
            this.currentEventSource = eventSource;
            
            // Create placeholder message for streaming content
            const messageElement = this.createStreamingMessageElement();
            let streamedContent = '';
            
            eventSource.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    debug('AIChatPageComponent: Streaming data received:', data);
                    
                    if (data.type === 'start') {
                        // Stream started
                        debug('AIChatPageComponent: Streaming started');
                    } else if (data.type === 'complete') {
                        // Stream completed
                        debug('AIChatPageComponent: Streaming completed');
                        const sources = data.sources || null;
                        const usage = data.usage || null;
                        const finalContent = data.message || streamedContent;
                        if (this.isAnonymous && finalContent) {
                            this.anonymousHistory.push({role: 'user', message: message});
                            this.anonymousHistory.push({role: 'assistant', message: finalContent});
                        }
                        this.finalizeStreamedMessage(messageElement, finalContent, sources, usage);
                        eventSource.close();
                        this.currentEventSource = null;
                        this.setLoading(false);
                        this.saveChatHistory();
                    } else if (data.error || data.type === 'error') {
                        // Server-provided error: let cleanupStreaming() handle everything
                        // (close + null + setLoading + cursor/class removal) – do NOT
                        // null out currentEventSource beforehand or the cleanup is skipped.
                        const serverError = data.error || 'An error occurred.';
                        this.cleanupStreaming(false);
                        if (serverError === 'no_service_available') {
                            const notice = this.container.dataset.noServiceAvailable
                                || 'No AI service is currently available.';
                            this.addMessageToDisplay('system', notice);
                            this.disableInputForUnavailableService(false);
                        } else {
                            this.addMessageToDisplay('system', serverError);
                        }
                        return;
                    } else if (data.type === 'chunk' && data.content) {
                        // Streaming chunk
                        const chunk = data.content;
                        streamedContent += chunk;
                        this.appendToStreamingMessage(messageElement, chunk);
                    }
                } catch (error) {
                    debugError('AIChatPageComponent: Error parsing streaming data:', {
                        error: error.message,
                        eventData: event.data,
                        chatId: this.chatId
                    });
                    
                    // Clean up streaming state without "stopped by user" message
                    this.cleanupStreaming(false);
                    this.addMessageToDisplay('system', 'Streaming error: Unable to process AI response. Please try again.');
                }
            };
            
            eventSource.onerror = (error) => {
                debugError('AIChatPageComponent: EventSource error:', error);
                
                // Clean up streaming state without "stopped by user" message
                this.cleanupStreaming(false);
                
                // Check if this might be a session expiration (common with streaming)
                if (!this.isSessionValid()) {
                    this.handleSessionExpired();
                } else {
                    this.addMessageToDisplay('system', 'Streaming connection error. Please try again.');
                }
            };
            
        } catch (error) {
            debugError('AIChatPageComponent: Streaming setup failed:', {
                error: error.message,
                apiUrl: this.apiUrl,
                chatId: this.chatId
            });
            this.setLoading(false);
            
            const userMessage = this.getErrorMessage(error);
            this.addMessageToDisplay('system', userMessage);
        }
    }
    
    /**
     * Create a placeholder message element for streaming content
     * 
     * Creates a new message element in the chat area with streaming styles
     * and a cursor indicator. Returns references to both the message container
     * and content area for use during streaming.
     * 
     * @private
     * @returns {{messageEl: HTMLElement, contentEl: HTMLElement}} References to message and content elements
     */
    createStreamingMessageElement() {
        const messageEl = document.createElement('div');
        messageEl.className = 'ai-chat-message assistant streaming';
        
        const contentEl = document.createElement('div');
        contentEl.className = 'ai-chat-message-content';
        contentEl.innerHTML = '<div class="streaming-cursor">|</div>';
        
        messageEl.appendChild(contentEl);
        this.messagesArea.appendChild(messageEl);
        this.scrollToBottom();
        
        return { messageEl, contentEl };
    }
    
    /**
     * Append a text chunk to the streaming message element
     * 
     * Updates the streaming message with new content chunk, maintaining
     * the raw content for final markdown processing and showing escaped
     * HTML with line breaks during streaming.
     * 
     * @private
     * @param {{messageEl: HTMLElement, contentEl: HTMLElement}} messageElement - Message element references
     * @param {string} chunk - New text content to append
     */
    appendToStreamingMessage(messageElement, chunk) {
        const { contentEl } = messageElement;
        
        // Store raw content during streaming (without markdown processing)
        if (!contentEl.dataset.rawContent) {
            contentEl.dataset.rawContent = '';
        }
        contentEl.dataset.rawContent += chunk;
        
        // Remove cursor and add chunk as plain text (no markdown processing during streaming)
        const cursor = contentEl.querySelector('.streaming-cursor');
        if (cursor) {
            cursor.remove();
        }
        
        // Show plain text with line breaks during streaming, add cursor
        const rawContent = contentEl.dataset.rawContent;
        contentEl.innerHTML = this.escapeHtml(rawContent).replace(/\n/g, '<br>') + '<span class="streaming-cursor">|</span>';
        this.scrollToBottom();
    }
    
    /**
     * Finalize the streamed message with full formatting
     * 
     * Removes streaming styles and cursor, applies markdown formatting
     * to the complete content, adds action buttons, and saves to history.
     * 
     * @private
     * @param {{messageEl: HTMLElement, contentEl: HTMLElement}} messageElement - Message element references
     * @param {string} finalContent - Complete message content for formatting
     * @param {Array|null} sources - RAG source citations if available
     * @param {Object|null} usage - Token usage data if available
     */
    finalizeStreamedMessage(messageElement, finalContent, sources = null, usage = null) {
        const { messageEl, contentEl } = messageElement;

        // Remove streaming class and cursor
        messageEl.classList.remove('streaming');
        const cursor = contentEl.querySelector('.streaming-cursor');
        if (cursor) {
            cursor.remove();
        }

        // Use stored raw content if available, otherwise use finalContent
        let contentToFormat = contentEl.dataset.rawContent || finalContent;

        // Strip inline sources and collect any web links the AI embedded in them
        let effectiveSources = sources;
        if (sources && sources.length > 0) {
            const { text: stripped, webLinks } = this.stripInlineSourcesWithLinks(contentToFormat, sources);
            contentToFormat = stripped;
            if (webLinks.length > 0) {
                effectiveSources = [...sources, ...webLinks];
            }
        }

        // Set final content with markdown formatting
        contentEl.innerHTML = this.formatMessage(contentToFormat);

        // Clear the raw content data
        delete contentEl.dataset.rawContent;

        // Add sources row if sources are available
        if (effectiveSources && effectiveSources.length > 0) {
            const sourcesRow = this.renderSourcesRow(effectiveSources, messageEl);
            messageEl.appendChild(sourcesRow);
            // Convert footnotes to chips (use original sources for index mapping)
            this.convertFootnotesToChips(contentEl, sourcesRow, effectiveSources);
        }

        // Add message action buttons
        const actionsEl = this.createMessageActions();

        // Admin raw data button
        if (this.isAdmin) {
            const rawBtn = document.createElement('button');
            rawBtn.className = 'ai-chat-message-action ai-chat-raw-btn';
            rawBtn.title = 'Raw data (Admin)';
            rawBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M5.854 4.854a.5.5 0 1 0-.708-.708l-3.5 3.5a.5.5 0 0 0 0 .708l3.5 3.5a.5.5 0 0 0 .708-.708L2.707 8l3.147-3.146zm4.292 0a.5.5 0 0 1 .708-.708l3.5 3.5a.5.5 0 0 1 0 .708l-3.5 3.5a.5.5 0 0 1-.708-.708L13.293 8l-3.147-3.146z"/></svg>`;
            rawBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleRawPanel(messageEl, finalContent, effectiveSources, usage);
            });
            actionsEl.appendChild(rawBtn);
        }

        messageEl.appendChild(actionsEl);

        // Add to message history (include sources and usage for persistence)
        this.messageHistory.push({
            role: 'assistant',
            content: contentToFormat,
            timestamp: Date.now(),
            sources: sources,
            usage: usage
        });

        this.scrollToBottom();
    }
    
    /**
     * Stop current streaming operation if active
     * 
     * Closes the EventSource connection, cleans up streaming state,
     * and adds a "stopped by user" indicator to any active streaming messages.
     * 
     * @public
     */
    stopStreaming() {
        this.cleanupStreaming(true);
    }
    
    /**
     * Clean up streaming state and UI elements
     * 
     * @private
     * @param {boolean} userStopped - Whether the user manually stopped streaming
     */
    cleanupStreaming(userStopped = false) {
        if (this.currentEventSource) {
            this.currentEventSource.close();
            this.currentEventSource = null;
            this.setLoading(false);
            
            // Clean up streaming UI elements
            const streamingMessages = this.messagesArea.querySelectorAll('.ai-chat-message.streaming');
            streamingMessages.forEach(msg => {
                msg.classList.remove('streaming');
                const contentEl = msg.querySelector('.ai-chat-message-content');
                if (contentEl) {
                    const cursor = contentEl.querySelector('.streaming-cursor');
                    if (cursor) {
                        cursor.remove();
                    }
                    
                    // Only add "stopped by user" message if user actually stopped it
                    if (userStopped) {
                        contentEl.innerHTML += '<em class="generation-stopped"> [Generation stopped by user]</em>';
                    }
                }
            });
        }
    }
    
    
    /**
     * Add a message to both the display and message history
     *
     * Creates a visible message element in the chat area and adds the message
     * to the internal history for context. Handles attachments if provided.
     *
     * @public
     * @param {string} role - Message role ('user', 'assistant', or 'system')
     * @param {string} content - Message content text
     * @param {Array<Object>} [attachments=[]] - Array of attachment objects to display
     * @param {Array<Object>} [sources=null] - Array of RAG source citations
     * @param {Object} [usage=null] - Token usage data
     */
    addMessageToDisplay(role, content, attachments = [], sources = null, usage = null) {
        this.displayMessageOnly(role, content, attachments, sources, usage);

        // Add to history
        this.messageHistory.push({
            role: role,
            content: content,
            timestamp: Date.now(),
            sources: sources,
            usage: usage
        });

        // Limit history size
        if (this.messageHistory.length > this.maxMemory * 2) {
            this.messageHistory = this.messageHistory.slice(-this.maxMemory * 2);
        }
    }
    
    /**
     * Display a message in the chat area without adding to history
     * 
     * Creates and renders a message element with proper styling based on role.
     * Handles markdown rendering for assistant messages and attachment display.
     * Does not modify the message history.
     * 
     * @public
     * @param {string} role - Message role ('user', 'assistant', or 'system')
     * @param {string} content - Message content text
     * @param {Array<Object>} [attachments=[]] - Array of attachment objects to display
     * @param {Array<Object>} [sources=null] - Array of RAG source citations
     * @param {Object} [usage=null] - Token usage data
     */
    displayMessageOnly(role, content, attachments = [], sources = null, usage = null) {
        debug('AIChatPageComponent: displayMessageOnly called with attachments:', attachments, 'sources:', sources);

        if (this.welcomeMsg && this.welcomeMsg.parentNode) {
            this.welcomeMsg.remove();
        }

        const messageDiv = document.createElement('div');
        messageDiv.className = 'ai-chat-message ' + role;

        // Create message content wrapper
        const contentWrapper = document.createElement('div');
        contentWrapper.className = 'ai-chat-message-content';

        // For assistant messages, render markdown
        if (role === 'assistant') {
            // Strip inline sources and collect web links from the AI's own sources section
            let displayContent = content;
            let effectiveSources = sources;
            if (sources && sources.length > 0) {
                const { text: stripped, webLinks } = this.stripInlineSourcesWithLinks(content, sources);
                displayContent = stripped;
                if (webLinks.length > 0) effectiveSources = [...sources, ...webLinks];
            }
            contentWrapper.innerHTML = this.renderMarkdown(displayContent);
            // Re-assign sources so the block below uses the merged list
            sources = effectiveSources;
        } else {
            contentWrapper.textContent = content;
        }
        
        // Add attachments if any - show as inline thumbnails for user messages
        if (attachments && attachments.length > 0) {
            const attachmentsDiv = document.createElement('div');
            attachmentsDiv.className = 'ai-chat-message-attachments';
            
            attachments.forEach(attachment => {
                debug('AIChatPageComponent: Processing attachment for display:', attachment);
                
                // Fallback to legacy handling for now to fix loading issues
                if (attachment.is_image || attachment.file_type === 'image') {
                    // Images: Show optimized thumbnail, click for full size
                    this.createImageAttachment(attachment, attachmentsDiv);
                } else if (attachment.file_type === 'pdf') {
                    // PDFs: Show preview thumbnail if available, or PDF icon
                    this.createPdfAttachment(attachment, attachmentsDiv);
                } else if (attachment.file_type === 'document') {
                    // Office documents: Show document icon with preview if available
                    this.createDocumentAttachment(attachment, attachmentsDiv);
                } else if (attachment.download_url) {
                    // Other files: Show generic file link
                    this.createGenericAttachment(attachment, attachmentsDiv);
                }
            });
            
            // Add attachments before the text content for user messages
            if (role === 'user') {
                messageDiv.appendChild(attachmentsDiv);
                messageDiv.appendChild(contentWrapper);
            } else {
                contentWrapper.appendChild(attachmentsDiv);
                messageDiv.appendChild(contentWrapper);
            }
        } else {
            // Add elements to message
            messageDiv.appendChild(contentWrapper);
        }
        
        // Add sources row and action buttons for assistant messages
        if (role === 'assistant') {
            // Add sources row FIRST if sources are available
            let sourcesRow = null;
            if (sources && sources.length > 0) {
                sourcesRow = this.renderSourcesRow(sources, messageDiv);
                messageDiv.appendChild(sourcesRow);

                // Convert footnotes to chips
                this.convertFootnotesToChips(contentWrapper, sourcesRow, sources);
            }

            // Then add action buttons
            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'ai-chat-message-actions';

            // Copy button
            const copyBtn = document.createElement('button');
            copyBtn.className = 'ai-chat-message-action';
            copyBtn.title = this.lang.copyMessageTitle;
            copyBtn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>
                    <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>
                </svg>
            `;
            copyBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.copyMessageToClipboard(content, copyBtn);
            });

            // Regenerate button
            const regenBtn = document.createElement('button');
            regenBtn.className = 'ai-chat-message-action';
            regenBtn.title = this.lang.regenerateResponseTitle;
            regenBtn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                    <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                    <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
                </svg>
            `;
            regenBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.regenerateResponse(messageDiv);
            });

            // Like button – temporarily hidden, uncomment to restore
            // const likeBtn = document.createElement('button');
            // likeBtn.className = 'ai-chat-message-action';
            // likeBtn.title = this.lang.likeResponseTitle;
            // likeBtn.innerHTML = `
            //     <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
            //         <path d="M8.864.046C7.908-.193 7.02.53 6.956 1.466c-.072 1.051-.23 2.016-.428 2.59-.125.36-.479 1.013-1.04 1.639-.557.623-1.282 1.178-2.131 1.41C2.685 7.288 2 7.87 2 8.72v4.001c0 .845.682 1.464 1.448 1.545 1.07.114 1.564.415 2.068.723l.048.03c.272.165.578.348.97.484.397.136.861.217 1.466.217h3.5c.937 0 1.599-.477 1.934-1.064a1.86 1.86 0 0 0 .254-.912c0-.152-.023-.312-.077-.464.201-.263.38-.578.488-.901.11-.33.172-.762.004-1.149.069-.13.12-.269.159-.403.077-.27.113-.568.113-.857 0-.288-.036-.585-.113-.856a2.144 2.144 0 0 0-.138-.362 1.9 1.9 0 0 0 .234-1.734c-.206-.592-.682-1.1-1.2-1.272-.847-.282-1.803-.276-2.516-.211a9.84 9.84 0 0 0-.443.05 9.365 9.365 0 0 0-.062-4.509A1.38 1.38 0 0 0 9.125.111L8.864.046z"/>
            //     </svg>
            // `;
            // likeBtn.addEventListener('click', (e) => {
            //     e.preventDefault();
            //     likeBtn.classList.toggle('ai-chat-message-action--active');
            //     dislikeBtn.classList.remove('ai-chat-message-action--active');
            // });

            // Dislike button – temporarily hidden, uncomment to restore
            // const dislikeBtn = document.createElement('button');
            // dislikeBtn.className = 'ai-chat-message-action';
            // dislikeBtn.title = this.lang.dislikeResponseTitle;
            // dislikeBtn.innerHTML = `
            //     <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
            //         <path d="M8.864 15.674c-.956.24-1.843-.484-1.908-1.42-.072-1.05-.23-2.015-.428-2.59-.125-.36-.479-1.012-1.04-1.638-.557-.624-1.282-1.179-2.131-1.41C2.685 8.432 2 7.85 2 7V3c0-.845.682-1.464 1.448-1.546 1.07-.113 1.564-.415 2.068-.723l.048-.029c.272-.166.578-.349.97-.484C6.931.08 7.395 0 8 0h3.5c.937 0 1.599.478 1.934 1.064.164.287.254.607.254.913 0 .152-.023.312-.077.464.201.262.38.577.488.9.11.33.172.762.004 1.15.069.13.12.268.159.403.077.27.113.567.113.856 0 .289-.036.586-.113.856-.035.12-.076.237-.138.362.133.358.197.714.197 1.03 0 .292-.073.582-.21.857a2.9 2.9 0 0 1-.024.047c-.205.592-.682 1.1-1.2 1.272-.847.283-1.803.276-2.516.211a9.877 9.877 0 0 1-.443-.05 9.364 9.364 0 0 1-.062 4.509c-.138.508-.55.848-1.012.964l-.261.065z"/>
            //     </svg>
            // `;
            // dislikeBtn.addEventListener('click', (e) => {
            //     e.preventDefault();
            //     dislikeBtn.classList.toggle('ai-chat-message-action--active');
            //     likeBtn.classList.remove('ai-chat-message-action--active');
            // });

            actionsDiv.appendChild(copyBtn);
            // actionsDiv.appendChild(likeBtn);
            // actionsDiv.appendChild(dislikeBtn);
            actionsDiv.appendChild(regenBtn);

            // Admin-only raw data button
            if (this.isAdmin) {
                const rawBtn = document.createElement('button');
                rawBtn.className = 'ai-chat-message-action ai-chat-raw-btn';
                rawBtn.title = 'Raw data (Admin)';
                rawBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M5.854 4.854a.5.5 0 1 0-.708-.708l-3.5 3.5a.5.5 0 0 0 0 .708l3.5 3.5a.5.5 0 0 0 .708-.708L2.707 8l3.147-3.146zm4.292 0a.5.5 0 0 1 .708-.708l3.5 3.5a.5.5 0 0 1 0 .708l-3.5 3.5a.5.5 0 0 1-.708-.708L13.293 8l-3.147-3.146z"/></svg>`;
                rawBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.toggleRawPanel(messageDiv, content, sources, usage);
                });
                actionsDiv.appendChild(rawBtn);
            }

            messageDiv.appendChild(actionsDiv);
        }

        this.messagesArea.appendChild(messageDiv);
        this.messagesArea.scrollTop = this.messagesArea.scrollHeight;

        // Announce new assistant messages to screen readers
        if (role === 'assistant') {
            this.announceToScreenReader(this.lang.newMessageAria);
        }
    }

    /**
     * Basic markdown renderer for AI responses
     */
    /**
     * Render markdown text to HTML with AI-specific formatting
     * 
     * Uses marked.js library if available, falls back to custom implementation.
     * Supports code blocks, inline code, headers, bold/italic, links, lists,
     * tables, blockquotes, and Mistral-specific thinking blocks.
     * 
     * @public
     * @param {string} text - Raw markdown text to render
     * @returns {string} HTML-formatted string
     */
    renderMarkdown(text) {
        if (!text) return '';

        // Use marked.js for proper markdown parsing
        if (typeof marked !== 'undefined') {
            // Configure marked options
            marked.setOptions({
                breaks: true,
                gfm: true,
                sanitize: false,
                smartypants: false
            });

            const renderer = new marked.Renderer();

            // ── Citation marker pre-processing ──────────────────────────────────
            // CommonMark closing-delimiter rule 2b: a run closing ** must be followed
            // by whitespace or punctuation when preceded by punctuation (e.g. ')').
            // Unicode superscripts ¹²³ have category "Other Number" (No) – neither
            // whitespace nor punctuation – so ')**¹' fails to close bold.
            // Carets '^' ARE punctuation, so ')**^1' works but ')**¹' does not.
            //
            // Fix A: convert ^N → ¹ (caret is punctuation so the bold already closes;
            //         conversion happens after so the rendered ** is already resolved)
            text = text.replace(/\^(\d+)/g, (_, n) => {
                const sup = ['⁰','¹','²','³','⁴','⁵','⁶','⁷','⁸','⁹'];
                return n.split('').map(d => sup[parseInt(d, 10)] || d).join('');
            });

            // Fix B: insert a space between a closing ** and a directly following
            // Unicode superscript so marked.js can recognise the bold delimiter.
            // The space becomes an invisible text node; convertFootnotesToChips
            // still finds and replaces the superscript immediately after.
            text = text.replace(/(\S)\*\*([¹²³⁴⁵⁶⁷⁸⁹⁰])/g, '$1** $2');

            // Apply Mistral-specific formatting before markdown processing
            text = this.renderMistralSpecialFormatting(text);

            let html = marked.parse(text, { renderer });
            // Open all external links in a new tab (post-process, renderer API varies by version)
            html = html.replace(/<a href="(https?:[^"]+)"/g, '<a href="$1" target="_blank" rel="noopener noreferrer"');
            return html;
        }
        
        // Fallback to custom implementation if marked.js not available
        return this.renderMarkdownFallback(text);
    }
    
    /**
     * Fallback markdown rendering when marked.js is not available
     * 
     * Custom implementation that handles basic markdown features using
     * regular expressions. Less robust than marked.js but provides
     * essential formatting support.
     * 
     * @private
     * @param {string} text - Raw markdown text to render
     * @returns {string} HTML-formatted string
     */
    renderMarkdownFallback(text) {
        // Start with HTML escaping
        let html = this.escapeHtml(text);
        
        // Apply basic transformations
        html = this.renderCodeBlocks(html);
        html = this.renderInlineCode(html);
        html = this.renderHeaders(html);
        html = this.renderBold(html);
        html = this.renderItalic(html);
        html = this.renderLinks(html);
        html = this.renderLists(html);
        html = this.renderTables(html);
        html = this.renderBlockquotes(html);
        html = this.renderMistralSpecialFormatting(html);
        html = this.renderLineBreaks(html);
        
        return html;
    }
    
    /**
     * Escape HTML special characters to prevent XSS attacks
     * 
     * Uses DOM API to safely escape HTML entities in user-provided text.
     * Essential for security when rendering user input or AI responses.
     * 
     * @public
     * @param {string} text - Raw text that may contain HTML characters
     * @returns {string} HTML-escaped text safe for display
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Render code blocks with syntax highlighting support
     * 
     * Converts markdown code blocks (```language\ncode\n```) to HTML
     * with proper CSS classes for syntax highlighting.
     * 
     * @private
     * @param {string} text - Text containing code block markdown
     * @returns {string} Text with code blocks converted to HTML
     */
    renderCodeBlocks(text) {
        // Multi-line code blocks with language
        text = text.replace(/```(\w+)?\n([\s\S]*?)\n```/g, (match, language, code) => {
            const lang = language || 'text';
            return `<div class="ai-chat-code-block">
                <div class="ai-chat-code-header">
                    <span class="ai-chat-code-language">${lang}</span>
                    <button class="ai-chat-code-copy" onclick="copyCodeToClipboard(this)" title="Copy code">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                            <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
                        </svg>
                    </button>
                </div>
                <pre class="ai-chat-code-content language-${lang}"><code>${code}</code></pre>
            </div>`;
        });
        
        return text;
    }
    
    renderInlineCode(text) {
        return text.replace(/`([^`]+)`/g, '<code class="ai-chat-inline-code">$1</code>');
    }
    
    renderHeaders(text) {
        text = text.replace(/^###### (.*)$/gm, '<h6>$1</h6>');
        text = text.replace(/^##### (.*)$/gm, '<h5>$1</h5>');
        text = text.replace(/^#### (.*)$/gm, '<h4>$1</h4>');
        text = text.replace(/^### (.*)$/gm, '<h3>$1</h3>');
        text = text.replace(/^## (.*)$/gm, '<h2>$1</h2>');
        text = text.replace(/^# (.*)$/gm, '<h1>$1</h1>');
        return text;
    }
    
    renderBold(text) {
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/__(.*?)__/g, '<strong>$1</strong>');
        return text;
    }
    
    renderItalic(text) {
        text = text.replace(/(?<!\*)\*([^*]+)\*(?!\*)/g, '<em>$1</em>');
        text = text.replace(/(?<!_)_([^_]+)_(?!_)/g, '<em>$1</em>');
        return text;
    }
    
    renderLinks(text) {
        // [text](url) links
        text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" class="ai-chat-link" target="_blank" rel="noopener noreferrer">$1</a>');
        
        // Auto-link URLs
        text = text.replace(/(https?:\/\/[^\s<>"]+)/g, '<a href="$1" class="ai-chat-link" target="_blank" rel="noopener noreferrer">$1</a>');
        
        return text;
    }
    
    renderLists(text) {
        // First, convert numbered items to list items with a marker
        text = text.replace(/^\d+\.\s+(.+)$/gm, '___ORDERED___<li class="ai-chat-list-item">$1</li>');
        
        // Find all consecutive ordered list items and wrap them in one <ol>
        let orderedListRegex = /(___ORDERED___<li class="ai-chat-list-item">.*?<\/li>(?:\s*___ORDERED___<li class="ai-chat-list-item">.*?<\/li>)*)/gs;
        text = text.replace(orderedListRegex, (match) => {
            // Remove the markers and wrap in <ol>
            const cleanMatch = match.replace(/___ORDERED___/g, '');
            return `<ol class="ai-chat-ordered-list">${cleanMatch}</ol>`;
        });
        
        // Clean up any remaining markers
        text = text.replace(/___ORDERED___/g, '');
        
        // Handle unordered lists
        text = text.replace(/^[-*+]\s+(.+)$/gm, '<li class="ai-chat-list-item">$1</li>');
        
        // Wrap consecutive unordered list items
        text = text.replace(/(<li class="ai-chat-list-item">.*?<\/li>(?:\s*<li class="ai-chat-list-item">.*?<\/li>)*)/gs, 
            '<ul class="ai-chat-list">$1</ul>');
        
        return text;
    }
    
    renderTables(text) {
        return text.replace(/^\|(.+)\|\s*\n\|[-\s:|]+\|\s*\n(\|.+\|\s*(?:\n|$))+/gm, (match) => {
            const lines = match.trim().split('\n');
            let html = '<table class="ai-chat-table">';
            
            // Header
            if (lines[0]) {
                const headerCells = lines[0].split('|').slice(1, -1);
                html += '<thead><tr class="ai-chat-table-header-row">';
                headerCells.forEach(cell => {
                    html += `<th class="ai-chat-table-header">${cell.trim()}</th>`;
                });
                html += '</tr></thead>';
            }
            
            // Body
            html += '<tbody>';
            for (let i = 2; i < lines.length; i++) {
                if (!lines[i].trim()) continue;
                const cells = lines[i].split('|').slice(1, -1);
                html += '<tr class="ai-chat-table-row">';
                cells.forEach(cell => {
                    html += `<td class="ai-chat-table-cell">${cell.trim()}</td>`;
                });
                html += '</tr>';
            }
            html += '</tbody></table>';
            
            return html;
        });
    }
    
    renderBlockquotes(text) {
        return text.replace(/^>\s*(.+(?:\n>\s*.+)*)/gm, (match) => {
            const content = match.replace(/^>\s*/gm, '');
            return `<div class="ai-chat-blockquote">${content}</div>`;
        });
    }
    
    renderMistralSpecialFormatting(text) {
        // Thinking blocks
        text = text.replace(/&lt;thinking&gt;([\s\S]*?)&lt;\/thinking&gt;/g, 
            `<div class="ai-chat-thinking-block"><div class="ai-chat-thinking-header">${this.lang.thinkingHeader}</div><div class="ai-chat-thinking-content">$1</div></div>`);
        
        // Math expressions
        text = text.replace(/\$\$([^$]+)\$\$/g, '<div class="ai-chat-math-block">$1</div>');
        text = text.replace(/\$([^$]+)\$/g, '<span class="ai-chat-math-inline">$1</span>');
        
        // Status blocks
        text = text.replace(/^⚠️\s*(.+)$/gm, '<div class="ai-chat-warning">⚠️ $1</div>');
        text = text.replace(/^ℹ️\s*(.+)$/gm, '<div class="ai-chat-info">ℹ️ $1</div>');
        text = text.replace(/^✅\s*(.+)$/gm, '<div class="ai-chat-success">✅ $1</div>');
        text = text.replace(/^❌\s*(.+)$/gm, '<div class="ai-chat-error">❌ $1</div>');
        
        // Step blocks
        text = text.replace(/^(Step \d+[:.]\s*.+)$/gm, '<div class="ai-chat-step">$1</div>');
        
        // Task lists
        text = text.replace(/^- \[ \]\s*(.+)$/gm, '<div class="ai-chat-task"><input type="checkbox" disabled> $1</div>');
        text = text.replace(/^- \[x\]\s*(.+)$/gm, '<div class="ai-chat-task"><input type="checkbox" checked disabled> $1</div>');
        
        return text;
    }
    
    renderLineBreaks(text) {
        // Convert double line breaks to paragraph breaks
        const paragraphs = text.split(/\n\s*\n/);
        let html = '';
        
        paragraphs.forEach(paragraph => {
            paragraph = paragraph.trim();
            if (!paragraph) return;
            
            // Don't wrap formatted blocks in paragraphs
            if (paragraph.match(/^<(div|ul|ol|table|blockquote|h[1-6])/)) {
                html += paragraph + '\n';
            } else {
                // Convert single line breaks to <br>
                paragraph = paragraph.replace(/\n/g, '<br>');
                html += `<p class="ai-chat-paragraph">${paragraph}</p>\n`;
            }
        });
        
        return html;
    }

    /**
     * Render RAG source citations as a collapsible list
     *
     * Creates an expandable section showing document sources that the AI
     * used to generate its response. Each source shows filename and page numbers.
     * Sources have IDs for linking from footnote markers in the text.
     *
     * @param {Array} sources - Array of source objects with filename, pages, excerpt
     * @param {string} messageId - Unique ID for this message to namespace source IDs
     * @returns {HTMLElement} Container element with rendered sources
     */
    renderSourcesCitation(sources, messageId = null) {
        const container = document.createElement('div');
        container.className = 'ai-chat-sources';

        // Generate unique ID for this sources section
        const sourcesId = messageId || 'src-' + Date.now();
        container.dataset.sourcesId = sourcesId;

        // Create collapsible header
        const header = document.createElement('button');
        header.className = 'ai-chat-sources-header';
        header.setAttribute('aria-expanded', 'false');
        header.innerHTML = `
            <span class="ai-chat-sources-icon" style="display:inline-flex;width:16px;height:16px;flex-shrink:0;">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" style="display:block;">
                    <path d="M4.5 0A2.5 2.5 0 0 0 2 2.5V14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0h-5zM9.5 3A1.5 1.5 0 0 0 11 4.5H14V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2.5A1.5 1.5 0 0 1 4.5 1H9v2.5z"/>
                    <path d="M4.5 12.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5z"/>
                </svg>
            </span>
            <span class="ai-chat-sources-label">${this.lang.sourcesLabel || 'Quellen'} (${sources.length})</span>
            <svg class="ai-chat-sources-chevron" width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/>
            </svg>
        `;

        // Create sources list
        const list = document.createElement('div');
        list.className = 'ai-chat-sources-list';
        list.style.display = 'none';

        sources.forEach((source, index) => {
            const item = document.createElement('div');
            item.className = 'ai-chat-source-item';
            item.id = `${sourcesId}-source-${index + 1}`;
            item.dataset.sourceIndex = index + 1;

            // Build page info string
            let pageInfo = '';
            if (source.pages && source.pages.length > 0) {
                const pageLabel = source.pages.length === 1 ?
                    (this.lang.pageLabel || 'Seite') :
                    (this.lang.pagesLabel || 'Seiten');
                pageInfo = `, ${pageLabel} ${source.pages.join(', ')}`;
            }

            item.innerHTML = `
                <span class="ai-chat-source-number">[${index + 1}]</span>
                <span class="ai-chat-source-filename">${this.escapeHtml(source.filename)}${pageInfo}</span>
            `;

            // Add excerpt tooltip if available
            if (source.excerpt) {
                item.dataset.excerpt = source.excerpt;
                this.addExcerptTooltip(item, source.excerpt);
            }

            list.appendChild(item);
        });

        // Toggle functionality
        header.addEventListener('click', () => {
            const isExpanded = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', !isExpanded);
            list.style.display = isExpanded ? 'none' : 'block';
            container.classList.toggle('expanded', !isExpanded);
        });

        container.appendChild(header);
        container.appendChild(list);

        // Store sources data for footnote linking
        container.sourcesData = sources;

        return container;
    }

    /**
     * Add hover tooltip with excerpt to an element
     *
     * @param {HTMLElement} element - Element to add tooltip to
     * @param {string} excerpt - Text excerpt to show in tooltip
     */
    addExcerptTooltip(element, excerpt) {
        let tooltip = null;

        element.addEventListener('mouseenter', (e) => {
            tooltip = document.createElement('div');
            tooltip.className = 'ai-chat-source-tooltip';

            // Render markdown in excerpt for proper formatting
            tooltip.innerHTML = this.renderMarkdown(excerpt);
            this.container.appendChild(tooltip);

            // Position tooltip
            const rect = element.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();

            // Position below element, but flip above if near bottom of viewport
            let top = rect.bottom + 5;
            if (top + tooltipRect.height > window.innerHeight - 20) {
                top = rect.top - tooltipRect.height - 5;
            }

            // Ensure tooltip stays within viewport horizontally
            let left = rect.left;
            const maxWidth = Math.min(400, window.innerWidth - 40);
            if (left + maxWidth > window.innerWidth - 20) {
                left = window.innerWidth - maxWidth - 20;
            }

            tooltip.style.left = `${Math.max(10, left)}px`;
            tooltip.style.top = `${top}px`;
            tooltip.style.maxWidth = `${maxWidth}px`;
        });

        element.addEventListener('mouseleave', () => {
            if (tooltip) {
                tooltip.remove();
                tooltip = null;
            }
        });
    }

    /**
     * Toggle raw data panel for admin debugging.
     * Shows original message text, RAG sources and token usage.
     */
    toggleRawPanel(messageEl, rawText, sources, usage) {
        let panel = messageEl.querySelector('.ai-chat-raw-panel');
        if (panel) {
            panel.remove();
            return;
        }

        const data = {
            message: rawText || '',
            sources: sources || [],
            usage:   usage   || {}
        };

        panel = document.createElement('details');
        panel.className = 'ai-chat-raw-panel';
        panel.open = true;
        panel.innerHTML = `<summary>Raw Data</summary><pre class="ai-chat-raw-pre">${this.escapeHtml(JSON.stringify(data, null, 2))}</pre>`;
        messageEl.appendChild(panel);
    }

    /**
     * Hover tooltip for source chips: shows full filename, pages and excerpt.
     * Uses position:fixed so it is never clipped by overflow:hidden parents.
     */
    addSourceInfoTooltip(element, sourceData) {
        let tooltip   = null;
        let closeTimer = null;

        const cancelClose = () => {
            if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        };

        const scheduleClose = () => {
            cancelClose();
            closeTimer = setTimeout(() => {
                if (tooltip) { tooltip.remove(); tooltip = null; }
            }, 120);
        };

        element.addEventListener('mouseenter', () => {
            cancelClose();

            if (tooltip) return; // already open

            tooltip = document.createElement('div');
            tooltip.className = 'ai-chat-source-tooltip ai-chat-source-info-tooltip';

            let html = `<div class="ai-chat-tooltip-filename">${this.escapeHtml(sourceData.filename)}</div>`;

            if (sourceData.pages && sourceData.pages.length > 0) {
                const pageLabel = sourceData.pages.length === 1
                    ? (this.container.dataset.pageLabel  || 'S.')
                    : (this.container.dataset.pagesLabel || 'S.');
                html += `<div class="ai-chat-tooltip-pages">${pageLabel} ${sourceData.pages.join(', ')}</div>`;
            }

            if (sourceData.excerpt) {
                html += `<div class="ai-chat-tooltip-excerpt">${this.renderMarkdown(sourceData.excerpt)}</div>`;
            }

            tooltip.innerHTML = html;
            this.container.appendChild(tooltip);

            // Keep tooltip open when the mouse moves into it
            tooltip.addEventListener('mouseenter', cancelClose);
            tooltip.addEventListener('mouseleave', scheduleClose);

            const rect     = element.getBoundingClientRect();
            const maxWidth = Math.min(380, window.innerWidth - 40);
            let left = rect.left;
            let top  = rect.bottom + 6;

            if (left + maxWidth > window.innerWidth - 20) {
                left = window.innerWidth - maxWidth - 20;
            }
            const tipHeight = tooltip.getBoundingClientRect().height;
            if (top + tipHeight > window.innerHeight - 20) {
                top = rect.top - tipHeight - 6;
            }

            tooltip.style.left     = `${Math.max(10, left)}px`;
            tooltip.style.top      = `${top}px`;
            tooltip.style.maxWidth = `${maxWidth}px`;
        });

        element.addEventListener('mouseleave', scheduleClose);
    }

    /**
     * Convert footnote markers in text to clickable links
     *
     * Finds superscript numbers (¹²³) in the content and converts them to
     * clickable links that expand the sources section and highlight the source.
     *
     * @param {HTMLElement} contentEl - Content element containing the rendered text
     * @param {HTMLElement} sourcesContainer - Sources container element
     * @param {Array} sources - Array of source objects with excerpts
     */
    linkFootnotesToSources(contentEl, sourcesContainer, sources) {
        if (!contentEl || !sourcesContainer || !sources || sources.length === 0) return;

        const sourcesId = sourcesContainer.dataset.sourcesId;

        // Map superscript numbers to regular numbers
        const superscriptMap = {
            '¹': 1, '²': 2, '³': 3, '⁴': 4, '⁵': 5,
            '⁶': 6, '⁷': 7, '⁸': 8, '⁹': 9, '⁰': 0
        };

        // Walk through text nodes and replace footnote markers
        const walker = document.createTreeWalker(contentEl, NodeFilter.SHOW_TEXT, null, false);
        const nodesToProcess = [];

        while (walker.nextNode()) {
            if (/[¹²³⁴⁵⁶⁷⁸⁹⁰]+/.test(walker.currentNode.textContent)) {
                nodesToProcess.push(walker.currentNode);
            }
        }

        nodesToProcess.forEach(textNode => {
            const fragment = document.createDocumentFragment();
            const text = textNode.textContent;
            let lastIndex = 0;

            // Match one or more superscript digits
            const regex = /[¹²³⁴⁵⁶⁷⁸⁹⁰]+/g;
            let match;

            while ((match = regex.exec(text)) !== null) {
                // Add text before match
                if (match.index > lastIndex) {
                    fragment.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
                }

                // Parse the superscript number
                let num = 0;
                for (const char of match[0]) {
                    num = num * 10 + (superscriptMap[char] || 0);
                }

                // Create clickable footnote link
                const link = document.createElement('a');
                link.href = '#' + sourcesId + '-source-' + num;
                link.className = 'ai-chat-footnote-link';
                link.style.cssText = 'color: #19c37d !important; text-decoration: none !important; cursor: pointer; font-weight: 600;';
                link.textContent = match[0];
                link.dataset.sourceIndex = num;

                // Get excerpt for this source
                const sourceData = sources[num - 1];
                if (sourceData && sourceData.excerpt) {
                    this.addExcerptTooltip(link, sourceData.excerpt);
                }

                // Click handler to open sources section and highlight item
                link.addEventListener('click', (e) => {
                    e.preventDefault();

                    // Open sources section
                    sourcesContainer.style.display = 'block';
                    // Also activate the button in action bar
                    const messageEl = sourcesContainer.closest('.ai-chat-message');
                    const btn = messageEl?.querySelector('.ai-chat-sources-btn');
                    if (btn) {
                        btn.classList.add('active');
                    }

                    // Find and highlight the source item
                    const targetId = sourcesId + '-source-' + num;
                    const targetItem = document.getElementById(targetId);
                    if (targetItem) {
                        targetItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        targetItem.classList.add('highlighted');
                        setTimeout(() => targetItem.classList.remove('highlighted'), 2000);
                    }
                });

                fragment.appendChild(link);
                lastIndex = regex.lastIndex;
            }

            // Add remaining text
            if (lastIndex < text.length) {
                fragment.appendChild(document.createTextNode(text.slice(lastIndex)));
            }

            textNode.parentNode.replaceChild(fragment, textNode);
        });
    }

    /**
     * Strip the "Quellen:"/"Sources:" section from message text
     *
     * Removes only the sources list at the end of the message since we display
     * sources separately. Keeps footnote markers (¹²³) in the text for reference.
     *
     * @param {string} text - Message text potentially containing inline sources
     * @returns {string} Cleaned text without the sources section at the end
     */
    stripInlineSources(text) {
        if (!text) return '';

        // Keywords to detect AI-generated source sections
        const kw = '(Quellen|Sources|Quellenangaben|Quellenverzeichnis|Referenzen|References|Literatur)';

        // Patterns ordered from most specific to least specific.
        // Optional leading --- separator, optional bold markers around the keyword.
        const patterns = [
            // ### **Quellen**: or --- \n ### **Quellen**:
            new RegExp('\\n?---\\s*\\n+#{1,6}\\s*\\*{0,2}' + kw + '\\*{0,2}\\s*:?[\\s\\S]*$', 'i'),
            // ### **Quellen**: (no preceding hr)
            new RegExp('\\n#{1,6}\\s*\\*{0,2}' + kw + '\\*{0,2}\\s*:?[\\s\\S]*$', 'i'),
            // **Quellen:** standalone bold line
            new RegExp('\\n\\s*\\*{1,2}' + kw + '\\*{1,2}\\s*:?[\\s\\S]*$', 'i'),
            // __Quellen:__ standalone
            new RegExp('\\n\\s*_{1,2}' + kw + '_{1,2}\\s*:?[\\s\\S]*$', 'i'),
            // Plain "Quellen:" line
            new RegExp('\\n\\s*' + kw + '\\s*:[\\s\\S]*$', 'i'),
        ];

        for (const pattern of patterns) {
            const match = text.match(pattern);
            // Only strip if found in the last ~60% of the text to avoid false positives
            if (match && match.index > text.length * 0.4) {
                text = text.substring(0, match.index);
                break;
            }
        }

        // Also handle bullet-point source lists without header at the end
        text = text.replace(/\n\n\s*(-\s+[^\n]+\.(pdf|doc|docx|txt|md|csv)[^\n]*\n?)+$/i, '');

        // Also handle numbered source lists without the header
        text = text.replace(/\n\n\s*(\d+\.\s+[^\n]+\.(pdf|doc|docx|txt|md|csv)[^\n]*\n?)+$/i, '');

        // Handle indented source lists with page references
        text = text.replace(/\n\n?\s*([\t ]*[^\n]+,\s*(page|pages|Seite|Seiten)\s+[\d,\s]+\n?)+$/i, '');

        // Clean up any trailing whitespace
        text = text.trimEnd();

        return text;
    }

    /**
     * Strip AI-generated sources section and return any web links found in it.
     * @param {string} text
     * @param {Array} ragSources - existing RAG sources (used to avoid duplicates)
     * @returns {{ text: string, webLinks: Array<{filename:string,url:string}> }}
     */
    stripInlineSourcesWithLinks(text, ragSources = []) {
        const ragFilenames = new Set((ragSources || []).map(s => s.filename));

        // Find where the sources section starts
        const kw = '(Quellen|Sources|Quellenangaben|Quellenverzeichnis|Referenzen|References|Literatur)';
        const sectionRegex = new RegExp(
            '(?:\\n?---\\s*\\n+|\\n)#{1,6}\\s*\\*{0,2}' + kw + '\\*{0,2}\\s*:?[\\s\\S]*$', 'i'
        );
        const match = text.match(sectionRegex);
        const webLinks = [];

        if (match && match.index > text.length * 0.4) {
            const section = match[0];
            // Extract markdown links [label](url) from the section
            const linkRegex = /\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g;
            let m;
            while ((m = linkRegex.exec(section)) !== null) {
                const label = m[1].trim();
                const url   = m[2].trim();
                if (!ragFilenames.has(label) && !ragFilenames.has(url)) {
                    webLinks.push({ filename: label, url, pages: [] });
                }
            }
        }

        return { text: this.stripInlineSources(text), webLinks };
    }

    // Attachment rendering methods for different file types
    createImageAttachment(attachment, container) {
        const imgDiv = document.createElement('div');
        imgDiv.className = 'ai-chat-message-image';
        imgDiv.style.cssText = `
            margin: 4px 0;
            border-radius: 8px;
            overflow: hidden;
            max-width: 300px;
            cursor: pointer;
        `;
        
        const img = document.createElement('img');
        img.style.cssText = `
            width: 100%;
            height: auto;
            max-height: 200px;
            object-fit: cover;
            border-radius: 8px;
        `;
        
        // Use thumbnail_url for optimized loading, fallback to data_url or original
        const imgSrc = attachment.data_url || attachment.thumbnail_url || attachment.preview_url || attachment.download_url;
        debug('AIChatPageComponent: Using optimized image src:', imgSrc);
        img.src = imgSrc;
        img.alt = attachment.title || 'Image';
        img.title = attachment.title || 'Image';
        
        // Error fallback
        img.onerror = () => {
            debug('AIChatPageComponent: Image failed to load, showing placeholder');
            img.src = this.createImagePlaceholder(attachment.title);
            img.onerror = null;
        };
        
        // Click to open full size (always use download_url for full quality)
        img.addEventListener('click', () => {
            window.open(attachment.download_url || attachment.src, '_blank');
        });
        
        imgDiv.appendChild(img);
        container.appendChild(imgDiv);
    }
    
    createPdfAttachment(attachment, container) {
        debug('AIChatPageComponent: Creating PDF attachment:', attachment);
        
        const pdfDiv = document.createElement('div');
        pdfDiv.className = 'ai-chat-message-pdf';
        
        // Try multiple preview sources in order, but only if they exist and are not null
        const previewSources = [
            attachment.preview_url,
            attachment.thumbnail_url,
            attachment.data_url // Base64 fallback if available
        ].filter(url => url && url !== null && url.trim() !== '');
        
        debug('AIChatPageComponent: PDF preview sources available:', previewSources);
        
        if (previewSources.length > 0) {
            // Show PDF preview thumbnail if available
            const img = document.createElement('img');
            img.alt = attachment.title || 'PDF Preview';
            img.title = 'Click to open PDF: ' + (attachment.title || 'Document');
            img.style.cursor = 'pointer';
            img.style.maxWidth = '300px';
            img.style.maxHeight = '200px';
            img.style.border = '1px solid #ddd';
            img.style.borderRadius = '4px';
            
            // Try loading preview sources sequentially
            let currentSourceIndex = 0;
            
            const tryNextSource = () => {
                if (currentSourceIndex < previewSources.length) {
                    const source = previewSources[currentSourceIndex];
                    debug('AIChatPageComponent: Trying PDF preview source:', source);
                    img.src = source;
                    currentSourceIndex++;
                } else {
                    // All sources failed, show PDF icon
                    debug('AIChatPageComponent: All PDF preview sources failed, showing icon');
                    img.src = this.createPdfIcon(attachment.title);
                    img.onerror = null;
                    img.style.width = '48px';
                    img.style.height = '48px';
                    img.style.border = 'none';
                }
            };
            
            // Set up error handler to try next source
            img.onerror = tryNextSource;
            
            // Start with first source
            tryNextSource();
            
            pdfDiv.appendChild(img);
        } else {
            // PDF attachment with improved readability
            const pdfContainer = document.createElement('div');
            pdfContainer.className = 'ai-chat-pdf-container';
            pdfContainer.style.cssText = `
                display: flex;
                align-items: center;
                padding: 8px 12px;
                border-radius: 8px;
                border: 1px solid #d0d0d0;
                background: white;
                max-width: 300px;
                cursor: pointer;
                margin: 4px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            `;
            
            // PDF icon with colored background circle
            const iconContainer = document.createElement('div');
            iconContainer.style.cssText = `
                width: 32px;
                height: 32px;
                border-radius: 6px;
                background: #ff6b6b;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 12px;
                flex-shrink: 0;
            `;
            
            const pdfIcon = document.createElement('div');
            pdfIcon.style.cssText = `
                font-size: 16px;
                color: white;
            `;
            pdfIcon.innerHTML = '📄';
            iconContainer.appendChild(pdfIcon);
            
            // File info container
            const fileInfoContainer = document.createElement('div');
            fileInfoContainer.style.cssText = `
                flex: 1;
                min-width: 0;
            `;
            
            // PDF filename (bold)
            const filename = document.createElement('div');
            filename.style.cssText = `
                font-size: 14px;
                font-weight: 600;
                color: #333;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                line-height: 1.2;
            `;
            const filenameText = (attachment.title || 'PDF Document').replace(/\.pdf$/i, '');
            filename.textContent = filenameText;
            
            // PDF type and size (lighter)
            const typeInfo = document.createElement('div');
            typeInfo.style.cssText = `
                font-size: 12px;
                color: #666;
                margin-top: 2px;
            `;
            const sizeKB = Math.round(attachment.size / 1024);
            typeInfo.textContent = `PDF • ${sizeKB} KB`;
            
            fileInfoContainer.appendChild(filename);
            fileInfoContainer.appendChild(typeInfo);
            
            pdfContainer.appendChild(iconContainer);
            pdfContainer.appendChild(fileInfoContainer);
            pdfDiv.appendChild(pdfContainer);
        }
        
        // Click to open PDF
        pdfDiv.addEventListener('click', () => {
            window.open(attachment.download_url, '_blank');
        });
        
        container.appendChild(pdfDiv);
    }
    
    createDocumentAttachment(attachment, container) {
        const docDiv = document.createElement('div');
        docDiv.className = 'ai-chat-message-document';
        
        // Document attachment with improved readability
        const docContainer = document.createElement('div');
        docContainer.className = 'ai-chat-document-container';
        docContainer.style.cssText = `
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d0d0d0;
            background: white;
            max-width: 300px;
            cursor: pointer;
            margin: 4px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        `;
        
        let bgColor = '#6366f1'; // Default purple
        let docType = 'DOC';
        let docIcon = '📄';
        
        if (attachment.mime_type.includes('word')) {
            bgColor = '#2b579a';
            docType = 'DOC';
            docIcon = '📝';
        } else if (attachment.mime_type.includes('excel') || attachment.mime_type.includes('sheet')) {
            bgColor = '#217346';
            docType = 'XLS';
            docIcon = '📊';
        } else if (attachment.mime_type.includes('powerpoint') || attachment.mime_type.includes('presentation')) {
            bgColor = '#d24726';
            docType = 'PPT';
            docIcon = '📽️';
        }
        
        // Document icon with colored background circle
        const iconContainer = document.createElement('div');
        iconContainer.style.cssText = `
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: ${bgColor};
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        `;
        
        const docIconEl = document.createElement('div');
        docIconEl.style.cssText = `
            font-size: 16px;
            color: white;
        `;
        docIconEl.innerHTML = docIcon;
        iconContainer.appendChild(docIconEl);
        
        // File info container
        const fileInfoContainer = document.createElement('div');
        fileInfoContainer.style.cssText = `
            flex: 1;
            min-width: 0;
        `;
        
        // Document filename (bold)
        const filename = document.createElement('div');
        filename.style.cssText = `
            font-size: 14px;
            font-weight: 600;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        `;
        const filenameText = (attachment.title || 'Document').replace(/\.(docx?|xlsx?|pptx?)$/i, '');
        filename.textContent = filenameText;
        
        // Document type and size (lighter)
        const typeInfo = document.createElement('div');
        typeInfo.style.cssText = `
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        `;
        const sizeKB = Math.round(attachment.size / 1024);
        typeInfo.textContent = `${docType} • ${sizeKB} KB`;
        
        fileInfoContainer.appendChild(filename);
        fileInfoContainer.appendChild(typeInfo);
        
        docContainer.appendChild(iconContainer);
        docContainer.appendChild(fileInfoContainer);
        docDiv.appendChild(docContainer);
        
        // Click to download/open
        docDiv.addEventListener('click', () => {
            window.open(attachment.download_url, '_blank');
        });
        
        container.appendChild(docDiv);
    }
    
    createGenericAttachment(attachment, container) {
        const genericDiv = document.createElement('div');
        genericDiv.className = 'ai-chat-message-generic';
        
        // Generic file attachment with improved readability
        const genericContainer = document.createElement('div');
        genericContainer.className = 'ai-chat-generic-container';
        genericContainer.style.cssText = `
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d0d0d0;
            background: white;
            max-width: 300px;
            cursor: pointer;
            margin: 4px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        `;
        
        // Generic file icon with colored background circle
        const iconContainer = document.createElement('div');
        iconContainer.style.cssText = `
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: #666666;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        `;
        
        const genericIcon = document.createElement('div');
        genericIcon.style.cssText = `
            font-size: 16px;
            color: white;
        `;
        genericIcon.innerHTML = '📁';
        iconContainer.appendChild(genericIcon);
        
        // File info container
        const fileInfoContainer = document.createElement('div');
        fileInfoContainer.style.cssText = `
            flex: 1;
            min-width: 0;
        `;
        
        // Filename (bold)
        const filename = document.createElement('div');
        filename.style.cssText = `
            font-size: 14px;
            font-weight: 600;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        `;
        const filenameText = (attachment.title || 'File').replace(/\.[^/.]+$/, '');
        filename.textContent = filenameText;
        
        // File type and size (lighter)
        const typeInfo = document.createElement('div');
        typeInfo.style.cssText = `
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        `;
        const ext = attachment.title ? attachment.title.split('.').pop().toUpperCase() : 'FILE';
        const sizeKB = Math.round(attachment.size / 1024);
        typeInfo.textContent = `${ext} • ${sizeKB} KB`;
        
        fileInfoContainer.appendChild(filename);
        fileInfoContainer.appendChild(typeInfo);
        
        genericContainer.appendChild(iconContainer);
        genericContainer.appendChild(fileInfoContainer);
        genericDiv.appendChild(genericContainer);
        
        // Click to download/open
        genericDiv.addEventListener('click', () => {
            window.open(attachment.download_url, '_blank');
        });
        
        container.appendChild(genericDiv);
    }
    
    createImagePlaceholder(title) {
        // Ultra-simple fallback that always works
        const simpleSvg = '<svg width="64" height="64" xmlns="http://www.w3.org/2000/svg"><rect width="64" height="64" fill="#f0f0f0" stroke="#ddd" stroke-width="1"/><text x="32" y="35" text-anchor="middle" font-family="Arial" font-size="12" fill="#666">Image</text></svg>';
        
        try {
            return 'data:image/svg+xml;base64,' + btoa(simpleSvg);
        } catch (e) {
            debugError('Failed to create placeholder:', e);
            // Even simpler fallback
            return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        }
    }
    
    createPdfIcon(title) {
        return 'data:image/svg+xml;base64,' + btoa(`
            <svg width="64" height="64" xmlns="http://www.w3.org/2000/svg">
                <rect width="64" height="64" fill="#ff6b6b" stroke="#e55555" stroke-width="2" rx="8"/>
                <text x="32" y="32" text-anchor="middle" dy="0.3em" font-family="Arial" font-size="12" fill="white" font-weight="bold">PDF</text>
                <text x="32" y="50" text-anchor="middle" dy="0.3em" font-family="Arial" font-size="6" fill="white">${title?.split('.')[0] || 'Document'}</text>
            </svg>
        `);
    }
    
    /**
     * Stop current AI generation request
     * 
     * Cancels both regular HTTP requests and streaming connections.
     * Updates UI to show generation was stopped by user.
     * 
     * @public
     */
    stopGeneration() {
        // Handle regular HTTP request abort
        if (this.currentRequest) {
            debug('AIChatPageComponent: Stopping HTTP request');
            this.currentRequest.abort();
            this.currentRequest = null;
            this.setLoading(false);
            this.addMessageToDisplay('system', this.lang.generationStopped);
        }
        
        // Handle streaming connection close
        if (this.currentEventSource) {
            debug('AIChatPageComponent: Stopping streaming');
            this.stopStreaming(); // This method handles EventSource cleanup
        }
    }
    
    /**
     * Set the loading state of the chat interface
     * 
     * Updates the send button to show stop icon during loading,
     * toggles loading indicators, and manages interaction state.
     * 
     * @public
     * @param {boolean} loading - Whether the chat is in loading state
     */
    setLoading(loading) {
        this.isLoading = loading;
        
        if (loading) {
            // Replace send button with stop button (use same icon as template)
            this.sendButton.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V2z"/>
                </svg>
            `;
            this.sendButton.className = 'ai-chat-composer-btn ai-chat-stop';
            this.sendButton.title = this.lang.stopGeneration || 'Stop generation';
            this.sendButton.disabled = false;
        } else {
            // Restore send button with correct icon from template
            this.sendButton.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="M8.99992 16V6.41407L5.70696 9.70704C5.31643 10.0976 4.68342 10.0976 4.29289 9.70704C3.90237 9.31652 3.90237 8.6835 4.29289 8.29298L9.29289 3.29298L9.36907 3.22462C9.76184 2.90427 10.3408 2.92686 10.707 3.29298L15.707 8.29298L15.7753 8.36915C16.0957 8.76192 16.0731 9.34092 15.707 9.70704C15.3408 10.0732 14.7618 10.0958 14.3691 9.7754L14.2929 9.70704L10.9999 6.41407V16C10.9999 16.5523 10.5522 17 9.99992 17C9.44764 17 8.99992 16.5523 8.99992 16Z"></path>
                </svg>
            `;
            this.sendButton.className = 'ai-chat-composer-btn ai-chat-send';
            this.sendButton.title = this.container.dataset.sendAriaLabel || 'Nachricht senden';
            
            // Check if button should remain disabled due to character limit
            const inputLength = this.inputArea.value.length;
            const isOverLimit = inputLength > this.charLimit;
            
            if (isOverLimit) {
                this.sendButton.disabled = true;
                this.sendButton.classList.add('disabled-over-limit');
                this.sendButton.title = this.container.dataset.sendDisabledOverLimit || 
                    `Message too long (${inputLength}/${this.charLimit} characters). Please shorten your message.`;
            } else {
                this.sendButton.disabled = false;
                this.sendButton.classList.remove('disabled-over-limit');
            }
        }
        
        if (this.loadingDiv) {
            this.loadingDiv.style.display = loading ? 'block' : 'none';
        }
    }
    
    /**
     * Update the character counter display and styling
     * 
     * Shows current character count, applies warning/error styles
     * when approaching or exceeding character limits.
     * 
     * @private
     */
    updateCharacterCounter() {
        if (this.charCounter) {
            const length = this.inputArea.value.length;
            this.charCounter.textContent = length;
            
            // Get the character counter container
            const charCounterContainer = this.container.querySelector('.ai-chat-char-counter');
            
            // Show/hide character counter based on text length
            if (charCounterContainer) {
                if (length > 0) {
                    charCounterContainer.classList.add('has-text');
                } else {
                    charCounterContainer.classList.remove('has-text');
                }
            }
            
            // Check if over character limit
            const isOverLimit = length > this.charLimit;
            
            // Update send button state based on character limit
            this.updateSendButtonState(isOverLimit, length === 0);
            
            // Add warning/error classes to character counter
            this.charCounter.classList.remove('warning', 'error');
            if (length > this.charLimit * 0.9) {
                this.charCounter.classList.add('warning');
            }
            if (isOverLimit) {
                this.charCounter.classList.add('error');
            }
        }
    }
    
    /**
     * Dynamic composer resize
     */
    resizeComposer() {
        const textarea = this.inputArea;
        const composer = this.container.querySelector('.ai-chat-composer');
        
        if (!textarea || !composer) return;
        
        // Auto-resize textarea height
        textarea.style.height = 'auto';
        const scrollHeight = textarea.scrollHeight;
        const computedStyle = getComputedStyle(textarea);
        const lineHeight = parseInt(computedStyle.lineHeight) || 24; // Higher default
        const maxLines = 10; // Maximum number of lines before scroll
        const maxHeight = lineHeight * maxLines; // Should be ~240px
        
        // Set height based on content, with max limit
        if (scrollHeight > maxHeight) {
            textarea.style.height = maxHeight + 'px';
        } else {
            textarea.style.height = scrollHeight + 'px';
        }
        
        // Dynamic approach: Calculate actual single-line capacity based on textarea width
        const text = textarea.value;
        const hasNewlines = text.includes('\n') || text.includes('\r');
        
        // Calculate approximate characters per line based on textarea width
        const textareaWidth = textarea.getBoundingClientRect().width;
        const avgCharWidth = 8; // Approximate pixels per character (varies by font)
        const estimatedCharsPerLine = Math.floor(textareaWidth / avgCharWidth);
        const isLongText = text.length > estimatedCharsPerLine;
        
        const heightExceedsOneLine = scrollHeight > (lineHeight + 20); // Allow some buffer
        
        // Expand if any of these conditions are true
        const isExpanded = hasNewlines || isLongText || heightExceedsOneLine;
        
        
        // Update composer state
        composer.setAttribute('data-expanded', isExpanded.toString());
        
        // Update grid layout based on content size
        if (isExpanded) {
            // Multi-line: full width for text, buttons below
            composer.querySelector('.ai-chat-composer-inner').style.gridTemplateAreas = 
                '"primary primary primary" "leading footer trailing"';
        } else {
            // Single line: horizontal layout
            composer.querySelector('.ai-chat-composer-inner').style.gridTemplateAreas = 
                '"leading primary trailing"';
        }
    }
    
    /**
     * Update send button state based on input validation
     * 
     * Disables send button when character limit is exceeded or when loading.
     * Provides visual feedback about why the button is disabled.
     * 
     * @private
     * @param {boolean} isOverLimit - Whether character limit is exceeded
     * @param {boolean} isEmpty - Whether input is empty
     */
    updateSendButtonState(isOverLimit, isEmpty) {
        if (!this.sendButton) return;
        
        // Don't modify button if currently loading (handled by setLoading)
        if (this.isLoading) return;
        
        const wasDisabled = this.sendButton.disabled;
        
        // Disable button if over character limit
        if (isOverLimit) {
            this.sendButton.disabled = true;
            this.sendButton.title = this.container.dataset.sendDisabledOverLimit || 
                `Message too long (${this.inputArea.value.length}/${this.charLimit} characters). Please shorten your message.`;
            this.sendButton.classList.add('disabled-over-limit');
        } else {
            // Enable button if not over limit and not empty
            this.sendButton.disabled = false;
            this.sendButton.title = this.container.dataset.sendAriaLabel || 'Send message';
            this.sendButton.classList.remove('disabled-over-limit');
        }
        
        // Log state changes for debugging
        if (wasDisabled !== this.sendButton.disabled) {
            debug('AIChatPageComponent: Send button state changed', {
                disabled: this.sendButton.disabled,
                reason: isOverLimit ? 'over_limit' : 'enabled',
                charCount: this.inputArea.value.length,
                charLimit: this.charLimit
            });
        }
    }
    
    /**
     * Handle file selection from input element
     * 
     * Processes selected files by uploading them and adding to attachments.
     * Clears the file input to allow re-selection of the same file.
     * 
     * @async
     * @public
     * @param {FileList} files - Files selected by user
     * @returns {Promise<void>}
     */
    async handleFileSelection(files) {
        if (!files || files.length === 0) {
            return;
        }
        
        for (let file of files) {
            await this.uploadFile(file);
        }
        
        // Clear the file input to allow re-uploading the same file
        this.fileInput.value = '';
    }
    

    async uploadFile(file) {
        // Debug file upload attempt
        debug('AIChatPageComponent: Attempting to upload file:', {
            name: file.name,
            type: file.type,
            size: file.size,
            allowedTypes: this.allowedFileTypes
        });
        
        // Check attachment limit first
        const maxAttachments = parseInt(this.container.dataset.maxAttachmentsPerMessage) || 5;
        if (this.attachments.length >= maxAttachments) {
            const errorMessage = this.container.dataset.errorMaxAttachments || `Maximum ${maxAttachments} attachments per message allowed.`;
            debug('AIChatPageComponent: Attachment limit exceeded');
            this.showAlert(errorMessage);
            return;
        }
        
        // Validate file size (configurable limit)
        const maxSizeMB = parseInt(this.container.dataset.maxFileSizeMb) || 5;
        const maxSize = maxSizeMB * 1024 * 1024;
        if (file.size > maxSize) {
            const errorMessage = this.container.dataset.errorFileTooLarge || `File too large. Maximum size is ${maxSizeMB}MB.`;
            debug('AIChatPageComponent: File size exceeded:', { size: file.size, maxSize: maxSize });
            this.showAlert(errorMessage);
            return;
        }
        
        // Validate file type against configured allowed types
        const allowedTypes = this.allowedFileTypes.length > 0 ? this.allowedFileTypes : [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain', 'text/csv', 'text/markdown'
        ];
        
        debug('AIChatPageComponent: File type validation:', {
            fileType: file.type,
            allowedTypes: allowedTypes,
            isAllowed: allowedTypes.includes(file.type)
        });
        
        if (!allowedTypes.includes(file.type)) {
            let errorMessage = this.container.dataset.errorFileTypeNotAllowed || `File type not allowed: ${file.type}`;
            errorMessage = errorMessage.replace('%s', file.type);
            debug('AIChatPageComponent: File type not allowed');
            this.showAlert(errorMessage);
            return;
        }
        
        // Generate preview URL for images to show in upload thumbnail
        let dataUrl = null;
        if (file.type.startsWith('image/')) {
            dataUrl = await this.createImagePreview(file);
        }
        
        // Create temporary upload preview with circular progress indicator
        const uploadingThumbnail = this.createUploadPreview(file, dataUrl);
        
        try {
            const formData = new FormData();
            formData.append('action', 'upload_file');
            formData.append('chat_id', this.chatId);
            formData.append('persistent', this.persistent);
            formData.append('file', file);
            
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                // Try to get user-friendly error from response
                let errorMsg = 'Upload failed';
                try {
                    const responseText = await response.text();
                    if (responseText) {
                        try {
                            const errorData = JSON.parse(responseText);
                            if (errorData.error) {
                                errorMsg = errorData.error;
                                if (errorData.details) {
                                    debugError('Upload error details:', errorData.details);
                                }
                            }
                        } catch (jsonError) {
                            debugError('Response is not JSON. Raw response:', responseText.substring(0, 500));
                            // Don't expose raw response to user
                        }
                    } else {
                        debugError('Empty response received');
                    }
                } catch (textError) {
                    debugError('Could not read response text:', textError);
                    // Don't expose technical details to user
                }
                throw new Error(errorMsg);
            }
            
            const data = await response.json();
            debug('AIChatPageComponent: Upload response data:', data);
            
            if (!data.success) {
                let errorMsg = data.error || 'Upload failed';
                if (data.details) {
                    debugError('Upload error details:', data.details);
                    // Don't expose technical details to user
                }
                throw new Error(errorMsg);
            }
            
            // Add attachment to list with local preview if available
            const attachment = data.attachment;
            debug('AIChatPageComponent: Server attachment data:', attachment);
            // Add local preview URL to attachment for immediate display
            if (dataUrl && attachment.is_image) {
                attachment.data_url = dataUrl;
                debug('AIChatPageComponent: Added local data_url to attachment');
            }
            
            // Remove temporary upload preview before rebuilding attachment display
            if (uploadingThumbnail) {
                this.clearThumbnailUploading(uploadingThumbnail);
            }
            
            this.attachments.push(attachment);
            this.updateAttachmentsDisplay();
            
        } catch (error) {
            debugError('File upload failed:', error);
            let errorMessage = this.container.dataset.errorFileUploadFailed || `File upload failed: ${error.message}`;
            errorMessage = errorMessage.replace('%s', error.message);
            this.showAlert(errorMessage);
            
            // Remove temporary upload preview on error
            if (uploadingThumbnail) {
                this.clearThumbnailUploading(uploadingThumbnail);
            }
        }
    }
    
    /**
     * Creates a temporary thumbnail preview with circular progress indicator for file uploads
     * @param {File} file - The file being uploaded
     * @param {string|null} dataUrl - Preview URL for images, null for other file types
     * @returns {HTMLElement|null} The created upload preview element
     */
    createUploadPreview(file, dataUrl) {
        if (!this.attachmentsList) return null;
        
        // Ensure attachment area is visible and styled
        this.attachmentsArea.style.display = 'flex';
        this.attachmentsArea.style.cssText += `
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
            padding: 8px;
        `;
        
        // Create thumbnail container with upload styling
        const uploadPreview = document.createElement('div');
        uploadPreview.className = 'ai-chat-attachment-uploading';
        uploadPreview.style.cssText = `
            position: relative;
            margin: 4px 0;
            border-radius: 8px;
            overflow: hidden;
            width: 80px;
            height: 80px;
            cursor: pointer;
            flex-shrink: 0;
        `;
        
        if (file.type.startsWith('image/') && dataUrl) {
            // Create image preview from file data
            const img = document.createElement('img');
            img.style.cssText = `
                width: 100%;
                height: 100%;
                object-fit: cover;
                border-radius: 8px;
            `;
            img.src = dataUrl;
            uploadPreview.appendChild(img);
        } else {
            // Create file type icon for non-images
            const fileIcon = document.createElement('div');
            fileIcon.style.cssText = `
                width: 100%;
                height: 100%;
                background: var(--chat-bg-secondary, #f5f5f5);
                border: 1px solid var(--chat-border, #ddd);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
            `;
            fileIcon.textContent = file.type.includes('pdf') ? '📄' : '📎';
            uploadPreview.appendChild(fileIcon);
        }
        
        // Add animated circular progress indicator
        const circleDiv = document.createElement('div');
        circleDiv.className = 'ai-chat-upload-circle';
        circleDiv.innerHTML = `
            <svg viewBox="0 0 20 20">
                <circle cx="10" cy="10" r="8"></circle>
            </svg>
        `;
        uploadPreview.appendChild(circleDiv);
        
        // Insert into attachment display area
        this.attachmentsList.appendChild(uploadPreview);
        
        return uploadPreview;
    }
    
    
    /**
     * Removes the temporary upload preview thumbnail
     * @param {HTMLElement} thumbnail - The upload preview element to remove
     */
    clearThumbnailUploading(thumbnail) {
        if (!thumbnail) return;
        
        // Remove temporary upload preview - real attachment thumbnail will be created by updateAttachmentsDisplay()
        thumbnail.remove();
    }
    
    
    
    
    
    updateAttachmentsDisplay() {
        debug('AIChatPageComponent: updateAttachmentsDisplay called', {
            attachmentsList: !!this.attachmentsList,
            attachmentsCount: this.attachments.length,
            attachments: this.attachments
        });
        
        if (!this.attachmentsList) {
            debug('AIChatPageComponent: attachmentsList not found, cannot update display');
            return;
        }
        
        this.attachmentsList.innerHTML = '';
        
        if (this.attachments.length === 0) {
            this.attachmentsArea.style.display = 'none';
            debug('AIChatPageComponent: No attachments, hiding area');
            return;
        }
        
        this.attachmentsArea.style.display = 'flex';
        this.attachmentsArea.style.cssText += `
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
            padding: 8px;
        `;
        
        debug('AIChatPageComponent: Showing attachments area, processing attachments');
        
        this.attachments.forEach((attachment, index) => {
            debug('AIChatPageComponent: Processing attachment', {
                index: index,
                attachment: attachment,
                is_image: attachment.is_image,
                has_data_url: !!attachment.data_url,
                has_preview_url: !!attachment.preview_url
            });
            
            if (attachment.is_image && (attachment.data_url || attachment.preview_url)) {
                // Image preview (same as in messages)
                debug('AIChatPageComponent: Creating image preview for attachment', index);
                this.createUploadImagePreview(attachment, index);
            } else {
                // Document preview (same style as in messages)
                debug('AIChatPageComponent: Creating document preview for attachment', index);
                this.createUploadDocumentPreview(attachment, index);
            }
        });
        
        debug('AIChatPageComponent: updateAttachmentsDisplay completed');
    }
    
    createUploadImagePreview(attachment, index) {
        const imageContainer = document.createElement('div');
        imageContainer.style.cssText = `
            position: relative;
            margin: 4px 0;
            border-radius: 8px;
            overflow: hidden;
            width: 80px;
            height: 80px;
            cursor: pointer;
            flex-shrink: 0;
        `;
        
        const img = document.createElement('img');
        img.style.cssText = `
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        `;
        const imgSrc = attachment.data_url || attachment.preview_url;
        img.src = imgSrc;
        img.alt = attachment.title;
        
        // Remove button (consistent styling)
        const removeBtn = this.createRemoveButton(index);
        
        imageContainer.appendChild(img);
        imageContainer.appendChild(removeBtn);
        this.attachmentsList.appendChild(imageContainer);
    }
    
    createUploadDocumentPreview(attachment, index) {
        // Same height as images (80px total)
        const docContainer = document.createElement('div');
        docContainer.style.cssText = `
            position: relative;
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d0d0d0;
            background: white;
            width: 200px;
            height: 80px;
            cursor: pointer;
            margin: 4px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            flex-shrink: 0;
        `;
        
        // Determine file type and colors
        let bgColor = '#ff6b6b'; // Default red for PDF
        let docType = 'PDF';
        let docIcon = '📄';
        
        if (attachment.mime_type) {
            if (attachment.mime_type.includes('word')) {
                bgColor = '#2b579a';
                docType = 'DOC';
                docIcon = '📝';
            } else if (attachment.mime_type.includes('excel') || attachment.mime_type.includes('sheet')) {
                bgColor = '#217346';
                docType = 'XLS';
                docIcon = '📊';
            } else if (attachment.mime_type.includes('powerpoint') || attachment.mime_type.includes('presentation')) {
                bgColor = '#d24726';
                docType = 'PPT';
                docIcon = '📽️';
            } else if (!attachment.mime_type.includes('pdf')) {
                bgColor = '#666666';
                docType = attachment.title ? attachment.title.split('.').pop().toUpperCase() : 'FILE';
                docIcon = '📁';
            }
        }
        
        // Icon container
        const iconContainer = document.createElement('div');
        iconContainer.style.cssText = `
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: ${bgColor};
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            flex-shrink: 0;
        `;
        
        const fileIcon = document.createElement('div');
        fileIcon.style.cssText = `
            font-size: 14px;
            color: white;
        `;
        fileIcon.innerHTML = docIcon;
        iconContainer.appendChild(fileIcon);
        
        // File info
        const fileInfoContainer = document.createElement('div');
        fileInfoContainer.style.cssText = `
            flex: 1;
            min-width: 0;
        `;
        
        const filename = document.createElement('div');
        filename.style.cssText = `
            font-size: 13px;
            font-weight: 600;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        `;
        const filenameText = (attachment.title || 'Document').replace(/\.(pdf|docx?|xlsx?|pptx?)$/i, '');
        filename.textContent = filenameText;
        
        const typeInfo = document.createElement('div');
        typeInfo.style.cssText = `
            font-size: 11px;
            color: #666;
            margin-top: 1px;
        `;
        const sizeKB = attachment.size ? Math.round(attachment.size / 1024) : '?';
        typeInfo.textContent = `${docType} • ${sizeKB} KB`;
        
        fileInfoContainer.appendChild(filename);
        fileInfoContainer.appendChild(typeInfo);
        
        // Remove button (consistent styling)
        const removeBtn = this.createRemoveButton(index);
        
        docContainer.appendChild(iconContainer);
        docContainer.appendChild(fileInfoContainer);
        docContainer.appendChild(removeBtn);
        this.attachmentsList.appendChild(docContainer);
    }
    
    createRemoveButton(index) {
        const removeBtn = document.createElement('button');
        removeBtn.className = 'ai-chat-upload-remove';
        removeBtn.style.cssText = `
            position: absolute;
            top: 4px;
            right: 4px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            color: #333;
            border: 1px solid rgba(0, 0, 0, 0.1);
            font-family: Arial, sans-serif;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            line-height: 1;
            padding: 0;
            margin: 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        `;
        removeBtn.textContent = '×';
        removeBtn.title = this.container.dataset.removeAttachment || 'Anhang entfernen';
        removeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.removeAttachment(index);
        });
        
        return removeBtn;
    }
    
    removeAttachment(index) {
        this.attachments.splice(index, 1);
        this.updateAttachmentsDisplay();
    }
    
    clearAttachments() {
        this.attachments = [];
        this.updateAttachmentsDisplay();
    }
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }
    
    async createImagePreview(file) {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                resolve(e.target.result);
            };
            reader.onerror = () => {
                resolve(null);
            };
            reader.readAsDataURL(file);
        });
    }
    
    /**
     * Disable input and show a notice when no AI service is configured.
     * History (if persistent) is still loaded and readable.
     */
    disableInputForUnavailableService(showMessage = true) {
        this.serviceUnavailable = true;

        if (this.inputArea) this.inputArea.disabled = true;

        const sendBtn = this.container.querySelector('.ai-chat-send');
        if (sendBtn) sendBtn.disabled = true;

        const attachBtn = this.container.querySelector('.ai-chat-attach-btn');
        if (attachBtn) attachBtn.disabled = true;

        if (showMessage) {
            const notice = this.container.dataset.noServiceAvailable
                || 'No AI service is currently available.';
            setTimeout(() => this.addMessageToDisplay('system', notice), 100);
        }
    }

    saveChatHistory() {
        // Anonymous sessions are ephemeral – nothing to persist
        if (this.isAnonymous) return;
        localStorage.setItem(`ai_chat_${this.chatId}`, JSON.stringify(this.messageHistory));
    }
    
    async loadChatHistory() {
        if (this.persistent) {
            // Load from server for persistent chats
            try {
                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'load_chat',
                        chat_id: this.chatId
                    })
                });
                
                if (response.ok) {
                    const data = await response.json();
                    debug('AIChatPageComponent: Loaded chat data:', data);
                    if (data.success && data.messages) {
                        debug('AIChatPageComponent: Raw messages from server:', data.messages);
                        this.messageHistory = data.messages.map(msg => ({
                            role: msg.role,
                            content: msg.content || msg.message || '',
                            timestamp: msg.timestamp || Date.now(),
                            attachments: msg.attachments || [],
                            sources: msg.sources || null,
                            usage: msg.usage || null
                        }));

                        debug('AIChatPageComponent: Processed message history:', this.messageHistory);
                        this.messageHistory.forEach(msg => {
                            if (msg.role !== 'system') {
                                debug('AIChatPageComponent: Displaying message:', msg);
                                // Skip empty messages (usually from file uploads without text)
                                if (msg.content.trim() === '' && (!msg.attachments || msg.attachments.length === 0)) {
                                    debug('AIChatPageComponent: Skipping empty message');
                                    return;
                                }
                                this.displayMessageOnly(msg.role, msg.content, msg.attachments || [], msg.sources, msg.usage);
                            }
                        });
                    }
                }
            } catch (e) {
                debugError('Failed to load persistent chat history:', e);
                // Fall back to local storage only for persistent chats
                this.loadLocalChatHistory();
            }
        } else {
            // Non-persistent chats should always start fresh - no history loading
            debug('Non-persistent chat - starting fresh without loading history');
            this.messageHistory = [];
            // Clear any existing local storage data for this chat
            localStorage.removeItem(`ai_chat_${this.chatId}`);
        }
        
        // Show welcome message if no history was loaded
        if (this.messageHistory.length === 0) {
            this.showWelcomeMessage();
        }
    }
    
    loadLocalChatHistory() {
        const saved = localStorage.getItem(`ai_chat_${this.chatId}`);
        if (saved) {
            try {
                this.messageHistory = JSON.parse(saved);
                this.messageHistory.forEach(msg => {
                    if (msg.role !== 'system') {
                        this.displayMessageOnly(msg.role, msg.content, msg.attachments || []);
                    }
                });
            } catch (e) {
                debugError('Failed to load local chat history:', e);
            }
        }
        
        // Show welcome message if no local history was loaded
        if (this.messageHistory.length === 0) {
            this.showWelcomeMessage();
        }
    }
    
    showWelcomeMessage() {
        this.messagesArea.innerHTML = `<div class="ai-chat-welcome">${this.lang.welcomeMessage}</div>`;
    }
    
    /**
     * Copy message content to clipboard
     */
    /**
     * Regenerate the last assistant response
     */
    async regenerateResponse(messageDiv) {
        debug('AIChatPageComponent: Regenerating response');
        
        // Find the last user message to regenerate from
        const messages = this.messagesArea.querySelectorAll('.ai-chat-message');
        let lastUserMessage = null;
        let lastUserAttachments = [];
        
        for (let i = messages.length - 1; i >= 0; i--) {
            if (messages[i] === messageDiv) {
                // Find the user message before this assistant message
                for (let j = i - 1; j >= 0; j--) {
                    if (messages[j].classList.contains('user')) {
                        lastUserMessage = messages[j];
                        break;
                    }
                }
                break;
            }
        }
        
        if (!lastUserMessage) {
            debugError('AIChatPageComponent: Could not find user message to regenerate from');
            return;
        }
        
        // Get the user message content
        const userContent = lastUserMessage.querySelector('.ai-chat-message-content').textContent;
        
        // Get attachments if any
        const attachmentDivs = lastUserMessage.querySelectorAll('.ai-chat-message-image img, .ai-chat-message-attachment');
        for (let attachmentDiv of attachmentDivs) {
            if (attachmentDiv.tagName === 'IMG') {
                // Extract attachment info from data attributes or stored data
                const attachmentId = attachmentDiv.dataset.attachmentId;
                if (attachmentId) {
                    // Find attachment in message history
                    const historyMsg = this.messageHistory.find(msg => 
                        msg.attachments && msg.attachments.some(att => att.id == attachmentId)
                    );
                    if (historyMsg) {
                        lastUserAttachments = historyMsg.attachments;
                    }
                }
            }
        }
        
        debug('AIChatPageComponent: Regenerating with message:', userContent);
        debug('AIChatPageComponent: With attachments:', lastUserAttachments);
        
        // Remove the assistant message
        messageDiv.remove();
        
        // Show loading
        this.setLoading(true);
        
        // Regenerate the response
        try {
            if (lastUserAttachments.length > 0) {
                if (this.enableStreaming) {
                    await this.sendMessageToAIStream(userContent, lastUserAttachments);
                } else {
                    await this.sendMessageWithFiles(userContent, lastUserAttachments);
                }
            } else {
                if (this.enableStreaming) {
                    await this.sendMessageToAIStream(userContent);
                } else {
                    await this.sendMessageToAI(userContent);
                }
            }
        } catch (error) {
            debugError('AIChatPageComponent: Regenerate failed:', error);
            this.setLoading(false);
            this.addMessageToDisplay('system', this.lang.regenerateFailed);
        }
    }
    
    copyMessageToClipboard(content, button) {
        // Try to copy to clipboard
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(content).then(() => {
                this.showMessageCopyFeedback(button, this.lang.messageCopied);
            }).catch(() => {
                this.fallbackMessageCopy(content, button);
            });
        } else {
            this.fallbackMessageCopy(content, button);
        }
    }
    
    /**
     * Fallback method for copying messages
     */
    fallbackMessageCopy(text, button) {
        // Try to select text in a temporary input
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            this.showMessageCopyFeedback(button, this.lang.messageCopied);
        } catch (err) {
            this.showMessageCopyFeedback(button, this.lang.messageCopyFailed);
        }
        
        document.body.removeChild(textArea);
    }
    
    /**
     * Show feedback for message copying
     */
    showMessageCopyFeedback(button, message) {
        debug('AIChatPageComponent: Starting copy feedback animation for:', message);
        
        // Create sliding animation container
        const originalContent = button.innerHTML;
        const originalTitle = button.title;
        
        // Set button to relative positioning for the animation
        const originalPosition = button.style.position;
        const originalOverflow = button.style.overflow;
        button.style.position = 'relative';
        button.style.overflow = 'visible'; // Changed to visible so text shows outside
        
        debug('AIChatPageComponent: Button prepared for animation');
        
        // Create the text element that will slide in
        const textElement = document.createElement('span');
        textElement.textContent = message;
        textElement.style.cssText = `
            position: absolute;
            top: 50%;
            left: -20px;
            transform: translateY(-50%);
            background: var(--chat-accent);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            z-index: 1000;
            transition: left 0.4s ease-out;
            pointer-events: none;
        `;
        
        button.appendChild(textElement);
        button.disabled = true;
        
        debug('AIChatPageComponent: Text element created and added');
        
        // Trigger the slide-in animation after a small delay
        setTimeout(() => {
            debug('AIChatPageComponent: Triggering slide-in animation');
            textElement.style.left = '32px'; // Move to right of button
        }, 50);
        
        // After 2 seconds, slide out and restore
        setTimeout(() => {
            debug('AIChatPageComponent: Starting slide-out animation');
            textElement.style.transition = 'left 0.4s ease-in, opacity 0.3s ease-in';
            textElement.style.left = '100px';
            textElement.style.opacity = '0';
            
            setTimeout(() => {
                debug('AIChatPageComponent: Cleaning up animation');
                try {
                    if (textElement.parentNode) {
                        button.removeChild(textElement);
                    }
                    button.style.position = originalPosition;
                    button.style.overflow = originalOverflow;
                    button.disabled = false;
                    button.title = originalTitle;
                } catch (error) {
                    debugError('AIChatPageComponent: Error cleaning up animation:', error);
                }
            }, 400);
        }, 2000);
    }

    /**
     * Announce message to screen readers via live region
     *
     * Updates the screen reader status element with a brief announcement
     * and clears it after a delay to prevent repeated readings.
     *
     * @private
     * @param {string} message - Message to announce
     */
    announceToScreenReader(message) {
        if (!this.srStatus) {
            return;
        }

        // Clear first to ensure announcement is read even if same text
        this.srStatus.textContent = '';

        // Use requestAnimationFrame to ensure the clear is processed
        requestAnimationFrame(() => {
            this.srStatus.textContent = message;

            // Clear after announcement is made
            setTimeout(() => {
                if (this.srStatus) {
                    this.srStatus.textContent = '';
                }
            }, 1000);
        });
    }

    /**
     * Clear all chat messages and history
     * 
     * Shows confirmation dialog, then clears local storage and server-side
     * chat data if persistent. Resets the chat interface to welcome state.
     * 
     * @async
     * @public
     * @returns {Promise<void>}
     */
    async clearChatHistory() {
        debug('AIChatPageComponent: clearChatHistory called');
        debug('AIChatPageComponent: Container dataset:', this.container.dataset);
        
        // Get localized confirmation text
        let confirmText = this.container.dataset.clearChatConfirm || 'Are you sure you want to clear all chat messages? This action cannot be undone.';
        
        // Decode HTML entities if present
        if (confirmText.includes('&')) {
            const textarea = document.createElement('textarea');
            textarea.innerHTML = confirmText;
            confirmText = textarea.value;
        }
        
        debug('AIChatPageComponent: Confirm text:', confirmText);
        
        // Use ILIAS modal dialog for confirmation
        debug('AIChatPageComponent: Showing ILIAS confirmation dialog');
        const userConfirmed = await this.showCustomConfirmDialog(confirmText);
        
        if (!userConfirmed) {
            debug('AIChatPageComponent: User cancelled clear chat');
            return;
        }
        
        debug('AIChatPageComponent: Starting clear chat request');
        
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'clear_chat',
                    chat_id: this.chatId
                })
            });
            
            debug('AIChatPageComponent: Clear chat response:', response.status);
            
            if (response.ok) {
                const data = await response.json();
                debug('AIChatPageComponent: Clear chat data:', data);
                
                if (data.success) {
                    debug('AIChatPageComponent: Chat cleared successfully, updating UI');
                    // Clear UI
                    this.messagesArea.innerHTML = `<div class="ai-chat-welcome">${this.lang.welcomeMessage || 'Start a conversation...'}</div>`;
                    this.messageHistory = [];
                    
                    // Clear local storage for non-persistent chats
                    if (!this.persistent) {
                        localStorage.removeItem(`ai_chat_${this.chatId}`);
                    }
                    
                    debug('AIChatPageComponent: UI cleared successfully');
                } else {
                    debugError('AIChatPageComponent: Server returned error:', data.error);
                    this.showAlert('Error clearing chat: ' + (data.error || 'Unknown error'));
                }
            } else {
                debugError('AIChatPageComponent: HTTP error:', response.status);
                this.showAlert('Failed to clear chat. Please try again.');
            }
        } catch (error) {
            debugError('Failed to clear chat:', error);
            this.showAlert('Error: ' + error.message);
        }
    }

    /**
     * Show ILIAS-style alert dialog
     */
    async showAlert(message) {
        return new Promise((resolve) => {
            // Create backdrop (same as ILIAS)
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade in';
            backdrop.style.cssText = `
                position: fixed;
                top: 0;
                right: 0;
                bottom: 0;
                left: 0;
                z-index: 1040;
                background-color: #000;
                opacity: 0.5;
            `;
            
            // Create modal container
            const modal = document.createElement('div');
            modal.className = 'modal fade in';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                right: 0;
                bottom: 0;
                left: 0;
                z-index: 1050;
                overflow: auto;
                display: block;
            `;
            
            modal.innerHTML = `
                <div class="modal-dialog" style="
                    width: 600px;
                    margin: 30px auto;
                    position: relative;
                ">
                    <div class="modal-content" style="
                        background-color: #fff;
                        border: 1px solid #999;
                        border-radius: 6px;
                        box-shadow: 0 3px 9px rgba(0,0,0,.5);
                        outline: 0;
                    ">
                        <div class="modal-header" style="
                            padding: 15px;
                            border-bottom: 1px solid #e5e5e5;
                            background-color: #f5f5f5;
                            border-radius: 6px 6px 0 0;
                        ">
                            <h4 class="modal-title" style="
                                margin: 0;
                                font-size: 18px;
                                line-height: 1.42857143;
                                color: #333;
                            ">Information</h4>
                        </div>
                        <div class="modal-body" style="
                            position: relative;
                            padding: 20px;
                        ">
                            <div class="alert alert-info" role="alert" style="
                                padding: 15px;
                                margin-bottom: 20px;
                                border: 1px solid #bce8f1;
                                border-radius: 4px;
                                color: #31708f;
                                background-color: #d9edf7;
                            ">${message}</div>
                        </div>
                        <div class="modal-footer" style="
                            padding: 15px;
                            text-align: right;
                            border-top: 1px solid #e5e5e5;
                            background-color: #f5f5f5;
                            border-radius: 0 0 6px 6px;
                        ">
                            <button type="button" class="btn btn-primary" id="alert-ok" style="
                                color: #fff;
                                background-color: #337ab7;
                                border-color: #2e6da4;
                                padding: 6px 12px;
                                margin-bottom: 0;
                                font-size: 14px;
                                font-weight: normal;
                                line-height: 1.42857143;
                                text-align: center;
                                white-space: nowrap;
                                vertical-align: middle;
                                cursor: pointer;
                                border: 1px solid transparent;
                                border-radius: 4px;
                            ">OK</button>
                        </div>
                    </div>
                </div>
            `;
            
            // Add to DOM
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.classList.add('modal-open');
            
            // Cleanup function
            const cleanup = () => {
                document.body.classList.remove('modal-open');
                document.body.removeChild(backdrop);
                document.body.removeChild(modal);
                resolve();
            };
            
            // Event listeners
            const okBtn = modal.querySelector('#alert-ok');
            okBtn.addEventListener('click', cleanup);
            
            // Focus OK button
            okBtn.focus();
        });
    }

    /**
     * Show custom confirmation dialog with ILIAS styling
     */
    showCustomConfirmDialog(message) {
        debug('AIChatPageComponent: Showing custom confirm dialog');
        
        // Create modal backdrop (same as ILIAS)
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade in';
        backdrop.style.cssText = `
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 1040;
            background-color: #000;
            opacity: 0.5;
        `;
        
        // Create modal container (same as ILIAS)
        const modal = document.createElement('div');
        modal.className = 'modal fade in';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 1050;
            display: block;
            overflow: auto;
        `;
        
        // Create modal dialog (same as ILIAS) with responsive width
        const dialog = document.createElement('div');
        dialog.className = 'modal-dialog';
        
        // Function to update dialog size based on window width
        const updateDialogSize = () => {
            const isDesktop = window.innerWidth >= 992;
            const dialogWidth = isDesktop ? '600px' : 'calc(100vw - 20px)';
            const dialogMargin = isDesktop ? '30px auto' : '10px';
            
            dialog.style.cssText = `
                position: relative;
                width: ${dialogWidth};
                max-width: 600px;
                margin: ${dialogMargin};
            `;
        };
        
        // Set initial size
        updateDialogSize();
        
        // Create modal content with ILIAS structure
        dialog.innerHTML = `
            <div class="modal-content" style="
                position: relative;
                background-color: #fff;
                background-clip: padding-box;
                border: 1px solid rgba(0, 0, 0, 0.2);
                border-radius: 0px;
                outline: 0;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            ">
                <div class="modal-header" style="
                    padding: 9px 15px;
                    border-bottom: 1px solid #e5e5e5;
                ">
                    <button type="button" class="close" aria-label="Abbrechen" style="
                        float: right;
                        font-size: 21px;
                        font-weight: bold;
                        line-height: 1;
                        color: #000;
                        text-shadow: 0 1px 0 #fff;
                        opacity: 0.2;
                        background: transparent;
                        border: 0;
                        cursor: pointer;
                    ">
                        <span aria-hidden="true">×</span>
                    </button>
                    <h1 class="modal-title" style="
                        font-size: 1rem;
                        margin: 0;
                        line-height: 1.428571429;
                        padding: 0px 39px;
                    ">Chat löschen</h1>
                </div>
                <div class="modal-body" style="
                    position: relative;
                    padding: 9px 15px;
                ">
                    <div class="alert alert-warning c-modal--interruptive__message" role="alert" style="
                        padding: 15px;
                        margin-bottom: 20px;
                        border: 1px solid transparent;
                        border-radius: 4px;
                        color: #8a6d3b;
                        background-color: #fcf8e3;
                        border-color: #faebcc;
                    ">
                        ${message}
                    </div>
                </div>
                <div class="modal-footer" style="
                    padding: 9px 15px;
                    text-align: right;
                    border-top: 1px solid #e5e5e5;
                ">
                    <button type="button" class="btn btn-primary" id="confirm-delete" style="
                        display: inline-block;
                        margin-bottom: 0;
                        font-weight: normal;
                        text-align: center;
                        vertical-align: middle;
                        touch-action: manipulation;
                        cursor: pointer;
                        background-image: none;
                        border: 1px solid transparent;
                        white-space: nowrap;
                        padding: 6px 12px;
                        font-size: 14px;
                        line-height: 1.428571429;
                        border-radius: 4px;
                        color: #fff;
                        background-color: #337ab7;
                        border-color: #2e6da4;
                        margin-left: 5px;
                    ">Löschen</button>
                    <button type="button" class="btn btn-default" id="confirm-cancel" style="
                        display: inline-block;
                        margin-bottom: 0;
                        font-weight: normal;
                        text-align: center;
                        vertical-align: middle;
                        touch-action: manipulation;
                        cursor: pointer;
                        background-image: none;
                        border: 1px solid transparent;
                        white-space: nowrap;
                        padding: 6px 12px;
                        font-size: 14px;
                        line-height: 1.428571429;
                        border-radius: 4px;
                        color: #333;
                        background-color: #fff;
                        border-color: #ccc;
                        margin-left: 5px;
                    ">Abbrechen</button>
                </div>
            </div>
        `;
        
        modal.appendChild(dialog);
        
        // Add to document
        document.body.appendChild(backdrop);
        document.body.appendChild(modal);
        document.body.classList.add('modal-open');
        
        return new Promise((resolve) => {
            const deleteBtn = modal.querySelector('#confirm-delete');
            const cancelBtn = modal.querySelector('#confirm-cancel');
            const closeBtn = modal.querySelector('.close');
            
            // Add resize listener for responsive behavior
            const resizeHandler = () => {
                updateDialogSize();
            };
            window.addEventListener('resize', resizeHandler);
            
            const cleanup = (result) => {
                document.body.classList.remove('modal-open');
                document.body.removeChild(backdrop);
                document.body.removeChild(modal);
                
                // Clean up event listeners
                window.removeEventListener('resize', resizeHandler);
                
                debug('AIChatPageComponent: Custom dialog result:', result);
                resolve(result);
            };
            
            deleteBtn.addEventListener('click', () => cleanup(true));
            cancelBtn.addEventListener('click', () => cleanup(false));
            closeBtn.addEventListener('click', () => cleanup(false));
            
            // Close on backdrop click
            backdrop.addEventListener('click', () => cleanup(false));
            
            // Handle Escape key
            const escapeHandler = (e) => {
                if (e.key === 'Escape') {
                    cleanup(false);
                    document.removeEventListener('keydown', escapeHandler);
                }
            };
            document.addEventListener('keydown', escapeHandler);
            
            // Focus delete button
            deleteBtn.focus();
        });
    }
    
    /**
     * Hide file upload elements when chat uploads are disabled
     * Note: With server-side conditional rendering, these elements may not exist at all
     */
    hideFileUploadElements() {
        // Hide attach button (may not exist if server-side disabled)
        if (this.attachBtn) {
            this.attachBtn.style.display = 'none';
        }
        
        // Hide attachments area (may not exist if server-side disabled)
        if (this.attachmentsArea) {
            this.attachmentsArea.style.display = 'none';
        }
        
        // Hide clear attachments button (may not exist if server-side disabled)
        if (this.clearAttachmentsBtn) {
            this.clearAttachmentsBtn.style.display = 'none';
        }
        
        // Hide file input (may not exist if server-side disabled)
        if (this.fileInput) {
            this.fileInput.style.display = 'none';
        }
    }
    
    /**
     * Update file input accept attribute based on global configuration
     *
     * Uses pre-computed accept values from server (MIME types + extensions)
     * for consistency with the editor background file upload.
     */
    updateFileInputAcceptAttribute() {
        if (!this.fileInput) {
            return;
        }

        // Use pre-computed accept values from server if available
        // This ensures consistency between editor and chat uploads
        if (this.allowedAcceptValues && this.allowedAcceptValues.length > 0) {
            const acceptString = this.allowedAcceptValues.join(',');
            this.fileInput.setAttribute('accept', acceptString);
            debug('AIChatPageComponent: Updated file input accept attribute (from server):', acceptString);
            return;
        }

        // Fallback: Build accept attribute from extensions if server values not available
        if (!this.allowedExtensions || this.allowedExtensions.length === 0) {
            return;
        }

        const acceptValues = [];

        // Add MIME types first
        if (this.allowedFileTypes && this.allowedFileTypes.length > 0) {
            acceptValues.push(...this.allowedFileTypes);
        }

        // Add file extensions as fallback
        this.allowedExtensions.forEach(ext => {
            acceptValues.push('.' + ext);
        });

        // Set the accept attribute on file input
        const acceptString = acceptValues.join(',');
        this.fileInput.setAttribute('accept', acceptString);

        debug('AIChatPageComponent: Updated file input accept attribute (fallback):', acceptString);
    }
    
    /**
     * Check if ILIAS session is still valid
     * 
     * Uses ILIAS session management patterns to detect expired sessions
     * before making API calls to provide better user experience.
     * 
     * @private
     * @returns {boolean} True if session appears to be valid
     */
    isSessionValid() {
        // Check if ILIAS session reminder is available and indicates valid session
        if (typeof $ !== 'undefined' && $.fn.ilSessionReminder) {
            // Check for ILIAS session cookies that indicate an active session
            const sessionCookies = document.cookie.split(';').filter(cookie => 
                cookie.trim().includes('PHPSESSID') || 
                cookie.trim().includes('il_') ||
                cookie.trim().includes('authtoken')
            );
            
            if (sessionCookies.length === 0) {
                debug('AIChatPageComponent: No ILIAS session cookies found');
                return false;
            }
        }
        
        // Additional check: if we're in an ILIAS environment, check for common ILIAS globals
        if (typeof window.il === 'undefined' && typeof window.ILIAS === 'undefined') {
            debug('AIChatPageComponent: ILIAS globals not available, session may be expired');
            return false;
        }
        
        return true; // Assume valid if checks pass
    }
    
    /**
     * Handle expired session with user-friendly messaging
     * 
     * Shows appropriate message and optionally redirects to login or
     * provides refresh option to restore session.
     * 
     * @private
     */
    handleSessionExpired() {
        debug('AIChatPageComponent: Session expired detected');
        
        // Show user-friendly session expired message
        const sessionExpiredMsg = this.container.dataset.sessionExpiredMessage || 
            'Your session has expired. Please refresh the page to log in again.';
            
        this.addMessageToDisplay('system', sessionExpiredMsg);
        
        // Disable input to prevent further attempts
        this.inputArea.disabled = true;
        this.sendButton.disabled = true;
        
        // Show refresh option
        if (this.container.dataset.showRefreshOnExpiry !== 'false') {
            this.showSessionExpiredOptions();
        }
    }
    
    /**
     * Show options for handling expired session
     * 
     * Provides user with options to refresh page or redirect to login.
     * 
     * @private
     */
    showSessionExpiredOptions() {
        const refreshText = this.container.dataset.refreshPageText || 'Refresh Page';
        const sessionDiv = document.createElement('div');
        sessionDiv.className = 'ai-chat-session-expired';
        sessionDiv.style.cssText = `
            margin: 10px 0;
            padding: 10px;
            border: 2px solid #f39c12;
            border-radius: 4px;
            background: #fff3cd;
            text-align: center;
        `;
        
        const refreshBtn = document.createElement('button');
        refreshBtn.textContent = refreshText;
        refreshBtn.className = 'btn btn-primary btn-sm';
        refreshBtn.style.cssText = 'margin: 5px;';
        refreshBtn.addEventListener('click', () => {
            window.location.reload();
        });
        
        sessionDiv.appendChild(refreshBtn);
        this.messagesArea.appendChild(sessionDiv);
        this.scrollToBottom();
    }
    
    /**
     * Get user-friendly error message based on error type
     * 
     * Converts technical errors into appropriate user-facing messages
     * while preserving debugging information in console logs.
     * 
     * @private
     * @param {Error} error - The error object to process
     * @returns {string} User-friendly error message
     */
    getErrorMessage(error) {
        // Server-provided sentinel for disabled services
        if (error.message === 'no_service_available') {
            return this.container.dataset.noServiceAvailable
                || 'No AI service is currently available. Please contact your administrator.';
        }
        // Session expired (302 redirect) - most common cause
        if (error.message.includes('302') || error.message.includes('redirect')) {
            return 'Your session has expired. Please refresh the page to log in again.';
        }
        
        // Network and connectivity errors
        if (error.name === 'TypeError' && error.message.includes('fetch')) {
            return 'Unable to connect to the AI service. Please check your internet connection and try again.';
        }
        
        if (error.name === 'NetworkError' || error.message.includes('Failed to fetch')) {
            return 'Network connection failed. Please check your internet connection and try again.';
        }
        
        // API configuration errors
        if (error.message.includes('API URL not configured')) {
            return 'AI service is not properly configured. Please contact your administrator.';
        }
        
        // Authentication errors
        if (error.message.includes('Invalid API key') || error.message.includes('401')) {
            return 'Authentication failed. The AI service credentials need to be updated.';
        }
        
        // Rate limiting
        if (error.message.includes('429') || error.message.includes('rate limit')) {
            return 'Too many requests. Please wait a moment and try again.';
        }
        
        // Server errors
        if (error.message.includes('500') || error.message.includes('503')) {
            return 'The AI service is temporarily unavailable. Please try again later.';
        }
        
        // File upload errors
        if (error.message.includes('File too large')) {
            return error.message; // Already user-friendly
        }
        
        if (error.message.includes('file type not allowed')) {
            return error.message; // Already user-friendly
        }
        
        // JSON parsing errors
        if (error.message.includes('JSON')) {
            return 'Received an invalid response from the AI service. Please try again.';
        }
        
        // Default fallback with generic message
        return `An error occurred while communicating with the AI service: ${error.message}. Please try again or contact support if the problem persists.`;
    }
    
    /**
     * Scroll messages area to bottom
     */
    scrollToBottom() {
        this.messagesArea.scrollTop = this.messagesArea.scrollHeight;
    }
    
    /**
     * Format message content (for streaming and regular messages)
     * Uses the same renderMarkdown method as non-streaming for consistency
     */
    formatMessage(content) {
        return this.renderMarkdown(content);
    }
    
    /**
     * Escape HTML entities
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Create message action buttons (copy, regenerate)
     * Sources are now handled separately via renderSourcesRow
     */
    createMessageActions() {
        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'ai-chat-message-actions';

        // Copy button
        const copyBtn = document.createElement('button');
        copyBtn.className = 'ai-chat-message-action';
        copyBtn.title = this.lang.copyMessageTitle;
        copyBtn.innerHTML = `
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>
                <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>
            </svg>
        `;
        copyBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const messageDiv = e.target.closest('.ai-chat-message');
            const contentDiv = messageDiv.querySelector('.ai-chat-message-content');
            const content = contentDiv ? contentDiv.textContent : '';
            this.copyMessageToClipboard(content, copyBtn);
        });

        // Regenerate button
        const regenBtn = document.createElement('button');
        regenBtn.className = 'ai-chat-message-action';
        regenBtn.title = this.lang.regenerateResponseTitle;
        regenBtn.innerHTML = `
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
            </svg>
        `;
        regenBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const messageDiv = e.target.closest('.ai-chat-message');
            this.regenerateResponse(messageDiv);
        });

        // // Like button – temporarily hidden
        // const likeBtn2 = document.createElement('button');
        // likeBtn2.className = 'ai-chat-message-action';
        // likeBtn2.title = this.lang.likeResponseTitle;
        // likeBtn2.addEventListener('click', (e) => {
        //     e.preventDefault();
        //     likeBtn2.classList.toggle('ai-chat-message-action--active');
        //     dislikeBtn2.classList.remove('ai-chat-message-action--active');
        // });

        // // Dislike button – temporarily hidden
        // const dislikeBtn2 = document.createElement('button');
        // dislikeBtn2.className = 'ai-chat-message-action';
        // dislikeBtn2.title = this.lang.dislikeResponseTitle;
        // dislikeBtn2.addEventListener('click', (e) => {
        //     e.preventDefault();
        //     dislikeBtn2.classList.toggle('ai-chat-message-action--active');
        //     likeBtn2.classList.remove('ai-chat-message-action--active');
        // });

        actionsDiv.appendChild(copyBtn);
        // actionsDiv.appendChild(likeBtn2);
        // actionsDiv.appendChild(dislikeBtn2);
        actionsDiv.appendChild(regenBtn);

        return actionsDiv;
    }

    /**
     * Detect source type from filename
     * @param {string} filename - Source filename
     * @returns {string} Source type: 'pdf', 'web', 'wiki', 'doc', 'image', 'other'
     */
    detectSourceType(filename, url = null) {
        if (url) return 'web';
        if (!filename) return 'other';
        const lower = filename.toLowerCase();

        if (lower.endsWith('.pdf')) return 'pdf';
        if (lower.includes('wiki') || lower.includes('wikipedia')) return 'wiki';
        if (lower.startsWith('http') || lower.includes('www.') || lower.endsWith('.html') || lower.endsWith('.htm')) return 'web';
        if (lower.endsWith('.doc') || lower.endsWith('.docx') || lower.endsWith('.odt')) return 'doc';
        if (lower.endsWith('.png') || lower.endsWith('.jpg') || lower.endsWith('.jpeg') || lower.endsWith('.gif') || lower.endsWith('.webp')) return 'image';
        if (lower.endsWith('.txt') || lower.endsWith('.md') || lower.endsWith('.csv')) return 'text';

        return 'other';
    }

    /**
     * Get icon SVG for source type
     * @param {string} type - Source type
     * @returns {string} SVG HTML
     */
    getSourceTypeIcon(type) {
        const icons = {
            pdf: `<svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0H4zm0 1h5v3.5A1.5 1.5 0 0 0 10.5 6H13v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1z"/><path d="M4.5 11.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1H5v1h1a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5v-2zm3 0a.5.5 0 0 1 .5-.5h1a1 1 0 0 1 0 2H8.5v.5a.5.5 0 0 1-1 0v-2z"/></svg>`,
            web: `<svg viewBox="0 0 16 16" fill="currentColor"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm7.5-6.923c-.67.204-1.335.82-1.887 1.855A7.97 7.97 0 0 0 5.145 4H7.5V1.077zM4.09 4a9.267 9.267 0 0 1 .64-1.539 6.7 6.7 0 0 1 .597-.933A7.025 7.025 0 0 0 2.255 4H4.09zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674a6.958 6.958 0 0 0-.656 2.5h2.49zM4.847 5a12.5 12.5 0 0 0-.338 2.5H7.5V5H4.847zM8.5 5v2.5h2.99a12.495 12.495 0 0 0-.337-2.5H8.5zM4.51 8.5a12.5 12.5 0 0 0 .337 2.5H7.5V8.5H4.51zm3.99 0V11h2.653c.187-.765.306-1.608.338-2.5H8.5zM5.145 12c.138.386.295.744.468 1.068.552 1.035 1.218 1.65 1.887 1.855V12H5.145zm.182 2.472a6.696 6.696 0 0 1-.597-.933A9.268 9.268 0 0 1 4.09 12H2.255a7.024 7.024 0 0 0 3.072 2.472zM3.82 11a13.652 13.652 0 0 1-.312-2.5h-2.49c.062.89.291 1.733.656 2.5H3.82zm6.853 3.472A7.024 7.024 0 0 0 13.745 12H11.91a9.27 9.27 0 0 1-.64 1.539 6.688 6.688 0 0 1-.597.933zM8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855.173-.324.33-.682.468-1.068H8.5zm3.68-1h2.146c.365-.767.594-1.61.656-2.5h-2.49a13.65 13.65 0 0 1-.312 2.5zm2.802-3.5a6.959 6.959 0 0 0-.656-2.5H12.18c.174.782.282 1.623.312 2.5h2.49zM11.27 2.461c.247.464.462.98.64 1.539h1.835a7.024 7.024 0 0 0-3.072-2.472c.218.284.418.598.597.933zM10.855 4a7.966 7.966 0 0 0-.468-1.068C9.835 1.897 9.17 1.282 8.5 1.077V4h2.355z"/></svg>`,
            wiki: `<svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8z"/><path d="M6.5 4.5a.5.5 0 0 1 .5.5v2.5h2V5a.5.5 0 0 1 1 0v6a.5.5 0 0 1-1 0V8h-2v3a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5z"/></svg>`,
            doc: `<svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0H4zm0 1h5v3.5A1.5 1.5 0 0 0 10.5 6H13v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1z"/><path d="M4.5 8a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5z"/></svg>`,
            image: `<svg viewBox="0 0 16 16" fill="currentColor"><path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/><path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-12zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1h12z"/></svg>`,
            text: `<svg viewBox="0 0 16 16" fill="currentColor"><path d="M4.5 11a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7zm0-2a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7zm0-2a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7zm0-2a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7z"/><path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2zm10-1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1z"/></svg>`,
            other: `<svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0H4zm0 1h5v3.5A1.5 1.5 0 0 0 10.5 6H13v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1z"/></svg>`
        };
        return icons[type] || icons.other;
    }

    /**
     * Get label for source type
     * @param {string} type - Source type
     * @returns {string} Label
     */
    getSourceTypeLabel(type) {
        const labels = {
            pdf: 'PDF',
            web: 'Web',
            wiki: 'Wiki',
            doc: 'Doc',
            image: 'Bild',
            text: 'Text',
            other: 'Datei'
        };
        return labels[type] || labels.other;
    }

    /**
     * Render sources row with type bubbles and collapsible section
     * @param {Array} sources - Array of source objects
     * @param {HTMLElement} messageEl - Message element
     * @returns {HTMLElement} Sources row element
     */
    renderSourcesRow(sources, messageEl) {
        const sourcesId = 'src-' + Date.now();

        // Detect unique source types
        const typeCount = {};
        sources.forEach(source => {
            const type = this.detectSourceType(source.filename, source.url);
            typeCount[type] = (typeCount[type] || 0) + 1;
        });
        const uniqueTypes = Object.keys(typeCount).slice(0, 3);

        // Create sources row container
        const row = document.createElement('div');
        row.className = 'ai-chat-sources-row';

        // Create toggle button with type bubbles
        const btn = document.createElement('button');
        btn.className = 'ai-chat-sources-toggle';
        btn.dataset.sourcesId = sourcesId;

        // Build bubbles HTML
        let bubblesHtml = '<div class="ai-chat-sources-bubbles">';
        uniqueTypes.forEach((type, i) => {
            bubblesHtml += `<span class="ai-chat-source-bubble" data-type="${type}" style="z-index: ${10 - i};">${this.getSourceTypeIcon(type)}</span>`;
        });
        bubblesHtml += '</div>';

        // Build type labels
        const typeLabels = uniqueTypes.map(t => `<span class="ai-chat-source-type-label">${this.getSourceTypeLabel(t)}</span>`).join('');

        btn.innerHTML = `
            ${bubblesHtml}
            <div class="ai-chat-sources-info">
                <div class="ai-chat-sources-types">${typeLabels}</div>
                <span class="ai-chat-sources-count">${sources.length} ${sources.length === 1 ? 'Quelle' : 'Quellen'}</span>
            </div>
            <svg class="ai-chat-sources-chevron" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/></svg>
        `;

        // Collapsible sources section
        const sourcesSection = document.createElement('div');
        sourcesSection.className = 'ai-chat-sources-section';
        sourcesSection.dataset.sourcesId = sourcesId;

        // Deduplicate sources by filename, merging page numbers across occurrences
        const deduped = [];
        const origToDedup = {}; // 1-based original index → 1-based dedup index

        sources.forEach((source, origIdx) => {
            const existing = deduped.findIndex(d => d.filename === source.filename);
            if (existing >= 0) {
                const merged = new Set([...(deduped[existing].pages || []), ...(source.pages || [])]);
                deduped[existing].pages = [...merged].sort((a, b) => a - b);
                origToDedup[origIdx + 1] = existing + 1;
            } else {
                deduped.push({ ...source, pages: [...(source.pages || [])] });
                origToDedup[origIdx + 1] = deduped.length;
            }
        });

        // Update toggle button count to reflect deduplicated number
        const countEl = btn.querySelector('.ai-chat-sources-count');
        if (countEl) {
            countEl.textContent = `${deduped.length} ${deduped.length === 1 ? 'Quelle' : 'Quellen'}`;
        }

        const sourcesList = document.createElement('div');
        sourcesList.className = 'ai-chat-sources-list';

        deduped.forEach((source, dedupIdx) => {
            const type = this.detectSourceType(source.filename, source.url);
            const item = document.createElement('div');
            item.className = 'ai-chat-source-item';
            item.id = `${sourcesId}-source-dedup-${dedupIdx + 1}`;
            item.dataset.type = type;

            let pageInfo = '';
            if (source.pages && source.pages.length > 0) {
                pageInfo = `<span class="ai-chat-source-pages">S. ${source.pages.join(', ')}</span>`;
            }

            const nameHtml = source.url
                ? `<a class="ai-chat-source-link" href="${this.escapeHtml(source.url)}" target="_blank" rel="noopener noreferrer">${this.escapeHtml(source.filename)}</a>`
                : `<span class="ai-chat-source-name">${this.escapeHtml(source.filename)}</span>`;

            const downloadBtn = source.download_url
                ? `<a class="ai-chat-source-download" href="${this.escapeHtml(source.download_url)}" target="_blank" rel="noopener noreferrer" title="Herunterladen" aria-label="Datei herunterladen"><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg></a>`
                : '';

            item.innerHTML = `
                <span class="ai-chat-source-icon" data-type="${type}">${this.getSourceTypeIcon(type)}</span>
                ${nameHtml}
                ${pageInfo}
                ${downloadBtn}
            `;

            // Add invisible anchors for every original index that maps to this item
            // so getElementById still works from chip click handlers
            Object.entries(origToDedup).forEach(([origIdx, dIdx]) => {
                if (parseInt(dIdx) === dedupIdx + 1) {
                    const anchor = document.createElement('span');
                    anchor.id = `${sourcesId}-source-${origIdx}`;
                    anchor.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden;pointer-events:none;';
                    item.appendChild(anchor);
                }
            });

            sourcesList.appendChild(item);
        });

        sourcesSection.appendChild(sourcesList);

        // Toggle animation
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const isOpen = sourcesSection.classList.contains('open');
            sourcesSection.classList.toggle('open', !isOpen);
            btn.classList.toggle('active', !isOpen);
        });

        row.appendChild(btn);
        row.appendChild(sourcesSection);

        // Store data for linking
        row.sourcesData = sources;
        row.dataset.sourcesId = sourcesId;

        return row;
    }

    /**
     * Convert footnote markers to clickable source chips
     * Supports multiple formats:
     * - Unicode superscripts: ¹²³⁴⁵⁶⁷⁸⁹⁰ (including multi-digit like ¹⁰, ¹¹)
     * - Bracket notation: [1], [10], [11]
     * - Caret notation: ^1, ^10, ^11
     *
     * @param {HTMLElement} contentEl - Content element
     * @param {HTMLElement} sourcesRow - Sources row element
     * @param {Array} sources - Sources array
     */
    convertFootnotesToChips(contentEl, sourcesRow, sources) {
        if (!contentEl || !sourcesRow || !sources || sources.length === 0) return;

        const sourcesId = sourcesRow.dataset.sourcesId;
        const self = this;
        const createdChips = []; // collected for post-DOM scroll measurement

        // Map superscript numbers to regular numbers
        const superscriptMap = {
            '¹': 1, '²': 2, '³': 3, '⁴': 4, '⁵': 5,
            '⁶': 6, '⁷': 7, '⁸': 8, '⁹': 9, '⁰': 0
        };

        /**
         * Parse footnote number from different formats
         * @param {string} matchStr - The matched string
         * @param {string} format - 'superscript', 'bracket', or 'caret'
         * @returns {number} The footnote number
         */
        function parseFootnoteNumber(matchStr, format) {
            if (format === 'superscript') {
                let num = 0;
                for (const char of matchStr) {
                    num = num * 10 + (superscriptMap[char] || 0);
                }
                return num;
            } else if (format === 'bracket') {
                // Extract number from [N]
                return parseInt(matchStr.slice(1, -1), 10);
            } else if (format === 'caret') {
                // Extract number from ^N
                return parseInt(matchStr.slice(1), 10);
            }
            return 0;
        }

        /**
         * Create a source chip element
         * @param {number} num - Source index (1-based)
         * @returns {HTMLElement|null} The chip element or null if source not found
         */
        function createSourceChip(num) {
            const sourceData = sources[num - 1];
            if (!sourceData) return null;

            const chip = document.createElement('span');
            chip.className = 'ai-chat-source-chip';
            chip.dataset.sourceIndex = num;

            const type = self.detectSourceType(sourceData.filename, sourceData.url);

            chip.innerHTML = `<span class="ai-chat-chip-icon" data-type="${type}">${self.getSourceTypeIcon(type)}</span><span class="ai-chat-chip-name"><span class="ai-chat-chip-text">${self.escapeHtml(sourceData.filename)}</span></span>`;

            createdChips.push(chip);

            // Fixed-position tooltip with full filename, pages and excerpt
            self.addSourceInfoTooltip(chip, sourceData);

            // Click to expand and highlight
            chip.addEventListener('click', (e) => {
                e.preventDefault();
                const section = sourcesRow.querySelector('.ai-chat-sources-section');
                const btn = sourcesRow.querySelector('.ai-chat-sources-toggle');
                if (section && btn) {
                    section.classList.add('open');
                    btn.classList.add('active');
                }
                let targetItem = document.getElementById(`${sourcesId}-source-${num}`);
                if (targetItem) {
                    // May be an invisible anchor inside the real item (dedup case)
                    if (!targetItem.classList.contains('ai-chat-source-item')) {
                        targetItem = targetItem.closest('.ai-chat-source-item') || targetItem;
                    }
                    targetItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    targetItem.classList.add('highlighted');
                    setTimeout(() => targetItem.classList.remove('highlighted'), 2000);
                }
            });

            return chip;
        }

        // Combined regex for all footnote formats:
        // - Unicode superscripts: [¹²³⁴⁵⁶⁷⁸⁹⁰]+
        // - Bracket notation: \[\d+\]
        // - Caret notation: \^\d+
        const combinedRegex = /([¹²³⁴⁵⁶⁷⁸⁹⁰]+|\[\d+\]|\^\d+)/g;

        // Walk through text nodes
        const walker = document.createTreeWalker(contentEl, NodeFilter.SHOW_TEXT, null, false);
        const nodesToProcess = [];

        while (walker.nextNode()) {
            if (combinedRegex.test(walker.currentNode.textContent)) {
                nodesToProcess.push(walker.currentNode);
                // Reset regex lastIndex after test
                combinedRegex.lastIndex = 0;
            }
        }

        nodesToProcess.forEach(textNode => {
            const fragment = document.createDocumentFragment();
            const text = textNode.textContent;
            let lastIndex = 0;
            let match;

            // Reset regex for each node
            combinedRegex.lastIndex = 0;

            while ((match = combinedRegex.exec(text)) !== null) {
                // Add text before match
                if (match.index > lastIndex) {
                    fragment.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
                }

                const matchStr = match[0];
                let format, num;

                // Determine format and parse number
                if (/^[¹²³⁴⁵⁶⁷⁸⁹⁰]+$/.test(matchStr)) {
                    format = 'superscript';
                    num = parseFootnoteNumber(matchStr, format);
                } else if (/^\[\d+\]$/.test(matchStr)) {
                    format = 'bracket';
                    num = parseFootnoteNumber(matchStr, format);
                } else if (/^\^\d+$/.test(matchStr)) {
                    format = 'caret';
                    num = parseFootnoteNumber(matchStr, format);
                }

                // Create chip if valid source exists
                const chip = num > 0 ? createSourceChip(num) : null;
                if (chip) {
                    fragment.appendChild(chip);
                } else {
                    // Keep original text if source not found
                    fragment.appendChild(document.createTextNode(matchStr));
                }

                lastIndex = combinedRegex.lastIndex;
            }

            if (lastIndex < text.length) {
                fragment.appendChild(document.createTextNode(text.slice(lastIndex)));
            }

            textNode.parentNode.replaceChild(fragment, textNode);
        });

        // After chips are in the DOM: measure real overflow and enable scroll animation.
        requestAnimationFrame(() => {
            createdChips.forEach(chip => {
                const nameEl = chip.querySelector('.ai-chat-chip-name');
                const textEl = chip.querySelector('.ai-chat-chip-text');
                if (!nameEl || !textEl) return;
                const overflow = textEl.scrollWidth - nameEl.clientWidth;
                if (overflow > 0) {
                    chip.style.setProperty('--chip-scroll-offset', `-${overflow}px`);
                    chip.classList.add('ai-chat-chip-scrollable');
                }
            });
        });
    }
}

// Initialize AI Chat components when DOM is ready
function initAIChatComponents() {
    debug('AIChatPageComponent: Initializing components...');
    
    const containers = document.querySelectorAll('.ai-chat-container');
    containers.forEach(container => {
        if (container.id) {
            debug('AIChatPageComponent: Initializing container:', container.id);
            new AIChatPageComponent(container.id);
        }
    });
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAIChatComponents);
} else {
    // DOM is already loaded - use small delay to ensure DOM is fully settled
    const DOM_READY_DELAY = 50; // ms
    setTimeout(initAIChatComponents, DOM_READY_DELAY);
}

/**
 * Get language strings from first AI chat instance on page
 */
function getAIChatLang() {
    const firstChatContainer = document.querySelector('.ai-chat-container');
    if (firstChatContainer) {
        return {
            messageCopied: firstChatContainer.dataset.messageCopied || 'Copied!',
            messageCopyFailed: firstChatContainer.dataset.messageCopyFailed || 'Failed to copy'
        };
    }
    // Fallback if no chat containers found
    return {
        messageCopied: 'Copied!',
        messageCopyFailed: 'Failed to copy'
    };
}

// Global function for code copying
function copyCodeToClipboard(button) {
    const codeBlock = button.closest('.ai-chat-code-block');
    const codeContent = codeBlock.querySelector('.ai-chat-code-content code');
    const text = codeContent.textContent;
    
    // Use modern clipboard API if available
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCodeCopyFeedback(button, getAIChatLang().messageCopied);
        }).catch(() => {
            fallbackCopyToClipboard(text, button);
        });
    } else {
        fallbackCopyToClipboard(text, button);
    }
}

function fallbackCopyToClipboard(text, button) {
    // Fallback for older browsers
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
        showCodeCopyFeedback(button, getAIChatLang().messageCopied);
    } catch (err) {
        showCodeCopyFeedback(button, getAIChatLang().messageCopyFailed);
    }
    
    document.body.removeChild(textArea);
}

function showCodeCopyFeedback(button, message) {
    const originalContent = button.innerHTML;
    button.innerHTML = message;
    button.disabled = true;
    
    setTimeout(() => {
        button.innerHTML = originalContent;
        button.disabled = false;
    }, 1500);
}