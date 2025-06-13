# Laravel GenAI

![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)
![Laravel Version](https://img.shields.io/badge/laravel-%5E11.0%7C%5E12.0-red)
![License](https://img.shields.io/badge/license-MIT-green)

A comprehensive Laravel package for integrating multiple AI providers (OpenAI, Gemini, Claude, Grok) with unified API, advanced caching, cost tracking, and detailed analytics.

## ✨ Features

### **Core Features**
- **🤖 Multiple AI Providers**: OpenAI, Google Gemini, Anthropic Claude, xAI Grok
- **🔄 Unified API**: Single interface for all providers with fluent chain syntax
- **📊 Cost Tracking**: Detailed cost analysis, budget management, and alerts
- **⚡ Advanced Caching**: Redis-based caching with TTL, tagging, and hit/miss tracking
- **📈 Analytics**: Comprehensive request logging and usage statistics
- **🎯 Preset Management**: Reusable prompt presets with variable substitution
- **⚙️ Rate Limiting**: Provider and model-specific rate limits with distributed control

### **Advanced Services**
- **🔔 NotificationService**: Multi-channel alerts (Email, Slack, Discord, Teams)
- **📈 PerformanceMonitoringService**: Real-time monitoring with P95/P99 tracking
- **💰 CostOptimizationService**: Intelligent optimization recommendations
- **🔄 PresetAutoUpdateService**: Automated preset optimization with versioning

### **Development Tools**
- **🔧 Artisan Commands**: 11 commands for installation, testing, model management
- **🧪 Testing Tools**: Built-in mocking, assertions, and verification commands
- **📥 Assistant Import**: OpenAI Assistant import to GenAI format
- **🌐 REST API**: 7 controllers with comprehensive endpoints
- **📋 Model Management**: YAML-based configuration with API fetching

## 📋 Requirements

- PHP 8.2 or higher
- Laravel 11.0+ or 12.0+
- Redis (recommended for caching)

## 📦 Installation

Install the package via Composer:

```bash
composer require cattyneo/laravel-genai
```

Run the installation command:

```bash
php artisan genai:install
```

This will:
- Publish configuration files
- Run database migrations
- Create necessary directories
- Display setup instructions

## ⚙️ Configuration

### Environment Variables

Add these to your `.env` file:

```env
# GenAI Configuration
GENAI_DEFAULT_PROVIDER=openai
GENAI_DEFAULT_MODEL=gpt-4.1-mini
GENAI_CACHE_ENABLED=true
GENAI_CACHE_DRIVER=redis

# API Keys
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...
CLAUDE_API_KEY=sk-ant-...
GROK_API_KEY=xai-...

# Optional: Custom API endpoints
OPENAI_MODELS_ENDPOINT=https://api.openai.com/v1/models
GEMINI_MODELS_ENDPOINT=https://generativelanguage.googleapis.com/v1beta/models
CLAUDE_MODELS_ENDPOINT=https://api.anthropic.com/v1/models
GROK_MODELS_ENDPOINT=https://api.x.ai/v1/models
```

### Configuration File

The main configuration is in `config/genai.php`. The comprehensive 279-line configuration includes:

#### **Core Settings**
- **defaults**: Default provider, model, timeout, async options, and request parameters
- **providers**: API endpoints, authentication, headers for OpenAI, Gemini, Claude, Grok
- **cache**: Redis/file caching with TTL, prefix, and tagging support
- **paths**: Configurable paths for presets and prompts storage

#### **Advanced Features**
- **pricing**: Currency settings, exchange rates, decimal precision for cost tracking
- **retry**: Retry attempts, exponential backoff, exception handling
- **logging**: Request/response logging, database optimization, batch processing
- **analytics**: History retention, cleanup policies, detailed analytics toggles

#### **Monitoring & Automation**
- **scheduled_tasks**: Automated model updates, deprecation checks with configurable frequency
- **notifications**: Multi-channel alerts (Email, Slack, Discord, Teams) with custom thresholds
- **preset_auto_update**: Automatic preset optimization with confidence thresholds and backup retention
- **rate_limits**: Provider-specific and model-specific rate limiting

#### **Advanced Services**
- **advanced_services**: Configuration for NotificationService, PerformanceMonitoringService, CostOptimizationService, and PresetAutoUpdateService

**Example core configuration:**
```php
'defaults' => [
    'timeout' => 30,
    'async' => true,
    'provider' => 'openai',
    'model' => 'gpt-4.1-mini',
    'options' => [
        'temperature' => 0.7,
        'max_tokens' => 2000,
        // ... additional options
    ],
],

'cache' => [
    'enabled' => true,
    'driver' => 'redis',
    'ttl' => 3600,
    'prefix' => 'genai_cache',
    'tags' => ['genai'],
],

'notifications' => [
    'deprecation_channels' => ['log', 'mail'],
    'cost_alert_channels' => ['log', 'mail'],
    'cost_thresholds' => [
        'warning' => 10000,  // ¥10,000
        'critical' => 50000, // ¥50,000
    ],
],
```

## 🚀 Quick Start

### Basic Usage

```php
use CattyNeo\LaravelGenAI\Facades\GenAI;

// Simple question
$response = GenAI::ask('Hello, how are you?');

// With specific provider and model
$response = GenAI::provider('openai')
    ->model('gpt-4o')
    ->ask('Explain Laravel in simple terms');

// Using presets
$response = GenAI::preset('blog')
    ->prompt('Write about AI and Laravel')
    ->request();
```

### Advanced Usage

```php
// With custom options
$response = GenAI::provider('claude')
    ->model('claude-3-opus')
    ->options([
        'temperature' => 0.7,
        'max_tokens' => 1000,
    ])
    ->ask('Create a detailed project plan');

// With streaming
$response = GenAI::provider('openai')
    ->options(['stream' => true])
    ->ask('Generate a long story')
    ->stream();

// Async requests
$response = GenAI::preset('analysis')
    ->options(['async' => true])
    ->prompt('Analyze this data: ' . $data)
    ->request();
```

## 📋 Preset Management

Create reusable prompt presets:

```markdown
---
title: Blog Generator
variables: [topic, tone]
model: gpt-4o
temperature: 0.8
---
Write a blog post about {{topic}} with a {{tone}} tone.
Include an introduction, main content, and conclusion.
```

Use presets in your code:

```php
$response = GenAI::preset('blog')
    ->vars(['topic' => 'AI Ethics', 'tone' => 'professional'])
    ->request();
```

## 🔧 Artisan Commands

### Installation and Setup

```bash
# Install the package
php artisan genai:install

# Install with options
php artisan genai:install --force --skip-migration
```

### Testing and Verification

```bash
# Test all providers
php artisan genai:test

# Test specific provider
php artisan genai:test --provider=openai

# Test with custom prompt
php artisan genai:test --prompt="Hello, world!" --provider=claude
```

### Statistics and Analytics

```bash
# Show usage statistics
php artisan genai:stats

# Statistics for specific period
php artisan genai:stats --days=30

# Detailed breakdown
php artisan genai:stats --detailed --provider=openai
```

### Model Management

```bash
# List available models from YAML and/or API
php artisan genai:model-list
php artisan genai:model-list --source=api --provider=openai
php artisan genai:model-list --format=json --details

# Add new model to YAML configuration
php artisan genai:model-add openai gpt-5 \
  --features=vision --features=reasoning \
  --max-tokens=32000 \
  --pricing-input=2.50 \
  --pricing-output=10.00

# Update model information from APIs
php artisan genai:model-update
php artisan genai:model-update --provider=openai --force

# Validate YAML configuration
php artisan genai:model-validate --verbose --fix

# Generate preset templates
php artisan genai:preset-generate "creative-writer" \
  --template=creative \
  --provider=openai \
  --model=gpt-4.1
```

### Advanced Analytics

```bash
# Advanced analytics with custom metrics
php artisan genai:analytics --period=weekly
php artisan genai:analytics --export=csv --provider=openai
php artisan genai:analytics --cost-analysis --threshold=1000

# Scheduled updates and automation
php artisan genai:scheduled-update --dry-run
php artisan genai:scheduled-update --force --backup
```

### OpenAI Assistant Import

Import OpenAI Assistants from your account:

```bash
# List available assistants
php artisan genai:assistant-import --list

# Import specific assistant
php artisan genai:assistant-import --id=asst_xxxxx

# Import all assistants
php artisan genai:assistant-import --all

# Check import status
php artisan genai:assistant-import --status

# Cleanup imported files
php artisan genai:assistant-import --cleanup
```

## 📊 Advanced Services

The package includes four advanced services for production-ready monitoring and optimization:

### 🔔 NotificationService
Multi-channel alert system for monitoring and notifications:

- **Email**: Cost alerts, performance warnings, deprecation notices
- **Slack**: Real-time notifications with formatted messages
- **Discord**: Community alerts and bot integration
- **Microsoft Teams**: Enterprise notifications with rich cards

```php
use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;

$notificationService = app(NotificationService::class);

// Send cost alert
$notificationService->sendCostAlert([
    'current_cost' => 150.0,
    'budget' => 100.0,
    'period' => 'daily'
]);

// Send performance alert
$notificationService->sendPerformanceAlert([
    'metric' => 'response_time',
    'current_value' => 5500,
    'threshold' => 5000,
    'degradation_percent' => 10.0
]);
```

### 📈 PerformanceMonitoringService
Real-time performance monitoring and metrics collection:

- Response time tracking (P95, P99 percentiles)
- Throughput analysis (RPS)
- Error rate monitoring
- Token usage analysis

### 💰 CostOptimizationService
Intelligent cost analysis and optimization recommendations:

- Model cost comparison
- Usage pattern analysis
- Budget management
- Optimization suggestions

### 🔄 PresetAutoUpdateService
Automated preset management with versioning:

- Automatic preset optimization
- Backup and restore capabilities
- Version control
- Confidence-based updates

## 🌐 REST API Endpoints

The package provides comprehensive REST API for integration:

### **Cost Analysis API**
```
GET /api/genai/cost/reports/monthly/{month?}  - Monthly cost report
GET /api/genai/cost/reports/weekly/{week?}    - Weekly cost report
GET /api/genai/cost/summary                   - Cost summary
GET /api/genai/cost/optimization-opportunities - Optimization opportunities
GET /api/genai/cost/budget-status             - Budget usage status
```

### **Performance Monitoring API**
```
GET /api/genai/performance/metrics/realtime   - Real-time metrics
GET /api/genai/performance/trends             - Performance trends
GET /api/genai/performance/history/{days?}    - Performance history
GET /api/genai/performance/alerts             - Performance alerts
GET /api/genai/performance/comparison         - Performance comparison
PUT /api/genai/performance/settings           - Update settings
```

### **Notification Management API**
```
GET /api/genai/notifications/history          - Notification history
GET /api/genai/notifications/alerts/active    - Active alerts
PUT /api/genai/notifications/alerts/{alert}/acknowledge - Acknowledge alert
GET /api/genai/notifications/settings         - Notification settings
PUT /api/genai/notifications/settings         - Update settings
POST /api/genai/notifications/test            - Test notification
```

### **Model Recommendations API**
```
GET /api/genai/recommendations                - General recommendations
GET /api/genai/recommendations/deprecated     - Deprecated model alternatives
POST /api/genai/recommendations/compare       - Model comparison
GET /api/genai/recommendations/optimization   - Optimization recommendations
```

## 📊 Analytics and Monitoring

The package provides comprehensive analytics:

### Cost Tracking
- Per-request cost calculation
- Provider-specific pricing
- Daily/monthly cost breakdowns
- Budget alerts and limits

### Usage Statistics
- Request counts and success rates
- Response time analysis
- Token usage tracking
- Error monitoring

### Database Tables

- `genai_requests`: Individual request logs
- `genai_stats`: Daily aggregated statistics

## 🔄 Caching

Intelligent caching with multiple strategies:

```php
// Cache configuration
'cache' => [
    'enabled' => true,
    'driver' => 'redis',
    'ttl' => 3600,
    'prefix' => 'genai:',
]
```

Cache keys are generated based on:
- Prompt content
- Model and provider
- Options and parameters

## ⚡ Rate Limiting

Configure rate limits per provider:

```php
'rate_limits' => [
    'default' => ['requests' => 100, 'per' => 'minute'],
    'openai' => ['requests' => 60, 'per' => 'minute'],
    'claude' => ['requests' => 40, 'per' => 'minute'],
]
```

## 🧪 Testing

The package includes comprehensive testing tools:

```php
// In your tests
GenAI::fake([
    'ask' => 'Mocked response',
    'request' => 'Another mock',
]);

// Assert requests
GenAI::assertRequested('ask', 'Hello');
```

## 🔒 Security

- API keys are never logged
- Request sanitization
- Rate limiting protection
- Input validation

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## 🆘 Support

- **Documentation**: [Full documentation](https://github.com/cattyneo/laravel-genai/wiki)
- **Issues**: [GitHub Issues](https://github.com/cattyneo/laravel-genai/issues)
- **Discussions**: [GitHub Discussions](https://github.com/cattyneo/laravel-genai/discussions)

## 🙏 Credits

- Built with ❤️ by [CattyNeo](https://github.com/cattyneo)
- Inspired by the Laravel community
- Thanks to all contributors
