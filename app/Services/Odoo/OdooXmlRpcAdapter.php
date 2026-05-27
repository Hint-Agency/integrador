<?php

namespace App\Services\Odoo;

use App\Services\Odoo\Contracts\OdooApiPort;

class OdooXmlRpcAdapter implements OdooApiPort
{
    public function __construct(
        protected array $config
    ) {}

    public function authenticate(): array
    {
        if (! class_exists(\Ripcord\Ripcord::class)) {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => 'Odoo XML-RPC adapter requires darkaonline/ripcord.',
                'adapter' => 'xml_rpc',
                'error' => [
                    'code' => 'missing_dependency',
                    'package' => 'darkaonline/ripcord',
                ],
            ];
        }

        try {
            $common = \Ripcord\Ripcord::client(rtrim((string) $this->config['url'], '/').'/xmlrpc/2/common');
            $uid = $common->authenticate(
                $this->config['database'],
                $this->config['username'],
                $this->config['password'],
                []
            );

            return [
                'success' => is_numeric($uid) && (int) $uid > 0,
                'uid' => (int) $uid,
                'status_code' => 200,
                'adapter' => 'xml_rpc',
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => 'Odoo XML-RPC authentication failed.',
                'adapter' => 'xml_rpc',
                'error' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): array
    {
        $auth = $this->authenticate();
        if (! $auth['success']) {
            return $auth;
        }

        try {
            $models = \Ripcord\Ripcord::client(rtrim((string) $this->config['url'], '/').'/xmlrpc/2/object');
            $result = $models->execute_kw(
                $this->config['database'],
                $auth['uid'],
                $this->config['password'],
                $model,
                $method,
                $args,
                $kwargs
            );

            if (is_array($result) && isset($result['faultCode'])) {
                return [
                    'success' => false,
                    'status_code' => 0,
                    'message' => 'Odoo XML-RPC request failed.',
                    'adapter' => 'xml_rpc',
                    'error' => $result,
                ];
            }

            return [
                'success' => true,
                'status_code' => 200,
                'adapter' => 'xml_rpc',
                'data' => ['result' => $result],
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => 'Odoo XML-RPC request failed.',
                'adapter' => 'xml_rpc',
                'error' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }
}
