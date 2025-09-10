<?php
// Test script for OpenRouter Social System Integration
// This script tests the OpenRouter API integration for social interactions

// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Use centralized session configuration
include_once '../includes/session_config.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/openrouter_api.php';
require_once __DIR__ . '/../includes/anthropic_api.php';

// Clear any unwanted output
ob_clean();
header('Content-Type: application/json');

// Check authentication and admin access
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Admin access required']);
    exit;
}

try {
    // Test 1: Check if OpenRouter API key is configured
    $openRouterKey = getOpenRouterApiKey();
    $hasOpenRouterKey = !empty($openRouterKey);
    
    // Test 2: Check if Anthropic API key is configured (for fallback)
    $anthropicKey = getAnthropicApiKey();
    $hasAnthropicKey = !empty($anthropicKey);
    
    $testResults = [
        'openrouter_configured' => $hasOpenRouterKey,
        'anthropic_configured' => $hasAnthropicKey,
        'api_test' => null,
        'model_info' => 'google/gemini-2.0-flash-exp:free'
    ];
    
    // Test 3: If OpenRouter is configured, test the API
    if ($hasOpenRouterKey) {
        try {
            // Test message for social interaction
            $testMessages = [
                [
                    'role' => 'user',
                    'content' => 'Generate a simple greeting for a friend named Alex.'
                ]
            ];
            
            $testSystemPrompt = "You are an AI assistant helping to generate social interactions. Keep responses brief and natural.";
            
            // Call the social system API (which uses OpenRouter)
            $startTime = microtime(true);
            $response = callSocialSystemAPI($testMessages, $testSystemPrompt, 500);
            $endTime = microtime(true);
            
            $processingTime = round(($endTime - $startTime) * 1000, 2); // Convert to milliseconds
            
            $testResults['api_test'] = [
                'success' => true,
                'response' => $response,
                'processing_time_ms' => $processingTime,
                'api_used' => 'openrouter',
                'model' => 'google/gemini-2.0-flash-exp:free'
            ];
            
        } catch (Exception $e) {
            $testResults['api_test'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'api_used' => 'openrouter',
                'fallback_available' => $hasAnthropicKey
            ];
        }
    } else {
        // Test with fallback to Anthropic
        if ($hasAnthropicKey) {
            try {
                $testMessages = [
                    [
                        'role' => 'user',
                        'content' => 'Generate a simple greeting for a friend named Alex.'
                    ]
                ];
                
                $testSystemPrompt = "You are an AI assistant helping to generate social interactions. Keep responses brief and natural.";
                
                $startTime = microtime(true);
                $response = callSocialSystemAPI($testMessages, $testSystemPrompt, 500);
                $endTime = microtime(true);
                
                $processingTime = round(($endTime - $startTime) * 1000, 2);
                
                $testResults['api_test'] = [
                    'success' => true,
                    'response' => $response,
                    'processing_time_ms' => $processingTime,
                    'api_used' => 'anthropic (fallback)',
                    'model' => 'claude-3-5-sonnet-20241022',
                    'note' => 'Using Anthropic as fallback since OpenRouter is not configured'
                ];
                
            } catch (Exception $e) {
                $testResults['api_test'] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'api_used' => 'anthropic (fallback)'
                ];
            }
        } else {
            $testResults['api_test'] = [
                'success' => false,
                'error' => 'Neither OpenRouter nor Anthropic API keys are configured',
                'api_used' => 'none'
            ];
        }
    }
    
    // Test 4: Test a more complex social interaction generation
    if ($testResults['api_test']['success']) {
        try {
            $complexTestMessages = [
                [
                    'role' => 'user',
                    'content' => 'Create a JSON response for a social interaction where a friend named Sarah shares exciting news about getting a new job. Include fields: interaction_type, message, emotional_tone, and expects_response.'
                ]
            ];
            
            $complexSystemPrompt = "You are generating social interactions for an AI companion system. Always respond with valid JSON.";
            
            $startTime = microtime(true);
            $complexResponse = callSocialSystemAPI($complexTestMessages, $complexSystemPrompt, 1000);
            $endTime = microtime(true);
            
            $processingTime = round(($endTime - $startTime) * 1000, 2);
            
            // Try to parse the JSON response
            $jsonParsed = false;
            $parsedData = null;
            
            // Extract JSON from response if it contains markdown code blocks
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $complexResponse, $matches)) {
                $parsedData = json_decode($matches[1], true);
                $jsonParsed = ($parsedData !== null);
            } elseif (preg_match('/\{.*\}/s', $complexResponse, $matches)) {
                $parsedData = json_decode($matches[0], true);
                $jsonParsed = ($parsedData !== null);
            }
            
            $testResults['complex_interaction_test'] = [
                'success' => true,
                'raw_response' => $complexResponse,
                'json_parsed' => $jsonParsed,
                'parsed_data' => $parsedData,
                'processing_time_ms' => $processingTime
            ];
            
        } catch (Exception $e) {
            $testResults['complex_interaction_test'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Summary
    $testResults['summary'] = [
        'status' => $testResults['api_test']['success'] ? 'operational' : 'not_operational',
        'recommendation' => $hasOpenRouterKey ? 
            'OpenRouter is configured and ' . ($testResults['api_test']['success'] ? 'working correctly' : 'encountered an error') :
            ($hasAnthropicKey ? 'OpenRouter not configured, using Anthropic as fallback' : 'No API keys configured - social system will not work'),
        'cost_efficiency' => $hasOpenRouterKey ? 
            'Using cost-efficient Google Gemini 2.0 Flash model via OpenRouter' : 
            'Using more expensive Anthropic Claude model as fallback'
    ];
    
    echo json_encode([
        'success' => true,
        'test_results' => $testResults,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("OpenRouter Social test error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Test failed: ' . $e->getMessage()
    ]);
}

ob_end_flush();
?>