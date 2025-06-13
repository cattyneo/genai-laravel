# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-06-05

### Added
- Initial release of Laravel GenAI package
- Support for multiple AI providers (OpenAI, Gemini, Claude, Grok)
- Unified API interface for all providers
- Advanced caching with Redis support
- Comprehensive cost tracking and analytics
- Preset management system for reusable prompts
- Rate limiting functionality
- Database logging for requests and statistics
- Artisan commands for installation, testing, and statistics
- Comprehensive test suite
- PHPDoc annotations and type hints
- Configuration management
- Error handling and logging

### Features
- **GenAI Manager**: Central management of AI providers
- **Provider Factory**: Dynamic provider instantiation
- **Cache Manager**: Intelligent caching strategies
- **Request Logger**: Detailed request and response logging
- **Cost Calculator**: Real-time cost tracking
- **Prompt Manager**: Template-based prompt management
- **Preset Repository**: Reusable configuration presets

### Artisan Commands
- `genai:install` - Package installation and setup
- `genai:test` - API connection testing
- `genai:stats` - Usage statistics and analytics

### Providers Supported
- **OpenAI**: GPT-4o, GPT-4o-mini, GPT-3.5-turbo
- **Google Gemini**: Gemini 2.5 Pro, Gemini 2.5 Flash
- **Anthropic Claude**: Claude 4 Opus, Claude 4 Sonnet
- **xAI Grok**: Grok 3, Grok 3 Fast

### Configuration Options
- Provider-specific settings
- Model configurations
- Cache settings
- Rate limiting rules
- Default options
- Cost tracking preferences

### Database Tables
- `genai_requests` - Individual request logs
- `genai_stats` - Daily aggregated statistics

## [0.1.0] - 2025-06-04

### Added
- Initial development version
- Basic provider implementations
- Core service architecture
- Testing framework setup
