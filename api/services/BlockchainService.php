<?php
require_once __DIR__ . '/../../config/blockchain.php';

class BlockchainService
{

    // Function Selector for addLog(string,string,string,string,string)
    // keccak256("addLog(string,string,string,string,string)")
    // = 9c8c7759...
    // First 4 bytes: 0x9c8c7759
    const FUNC_SELECTOR = '0x9c8c7759';

    /**
     * Send transaction to Ganache
     */
    public static function addLog($reportCode, $actionType, $dataHash, $dataPayload, $actorRole)
    {
        if (!defined('BLOCKCHAIN_ENABLED') || !BLOCKCHAIN_ENABLED) {
            return false;
        }

        $rpcUrl = BLOCKCHAIN_RPC_URL;
        $contractAddress = BLOCKCHAIN_CONTRACT_ADDRESS;
        $fromAddress = BLOCKCHAIN_FROM_ADDRESS;

        // Encode Parameters
        $data = self::encodeABI([$reportCode, $actionType, $dataHash, $dataPayload, $actorRole]);
        $txData = self::FUNC_SELECTOR . $data;

        // JSON-RPC Payload
        $payload = json_encode([
            "jsonrpc" => "2.0",
            "method" => "eth_sendTransaction",
            "params" => [[
                    "from" => $fromAddress,
                    "to" => $contractAddress,
                    "data" => $txData,
                    "gas" => "0x2DC6C0" // 3,000,000 Gas Limit
                ]],
            "id" => 1
        ]);

        // Curl Request
        $ch = curl_init($rpcUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Blockchain Error: " . $error);
            return false;
        }

        $result = json_decode($response, true);
        if (isset($result['error'])) {
            error_log("Blockchain RPC Error: " . json_encode($result['error']));
            return false;
        }

        return $result['result']; // Transaction Hash
    }

    /**
     * Simple ABI Encoder for dynamic strings
     * Assumes all inputs are strings
     */
    private static function encodeABI($args)
    {
        $head = '';
        $tail = '';

        // Calculate initial offset: 32 bytes per argument
        $offset = count($args) * 32;

        foreach ($args as $arg) {
            // Append offset to head
            $head .= str_pad(dechex($offset), 64, '0', STR_PAD_LEFT);

            // Process string content
            $utf8 = $arg;
            $len = strlen($utf8);
            $paddedLen = ceil($len / 32) * 32;

            // Append length and data to tail
            $tail .= str_pad(dechex($len), 64, '0', STR_PAD_LEFT);
            $tail .= str_pad(bin2hex($utf8), $paddedLen * 2, '0', STR_PAD_RIGHT);

            // Update offset for next argument
            $offset += 32 + $paddedLen;
        }

        return $head . $tail;
    }
}
