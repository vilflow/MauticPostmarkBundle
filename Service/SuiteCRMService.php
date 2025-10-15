<?php

namespace MauticPlugin\MauticPostmarkBundle\Service;

class SuiteCRMService
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private ?string $accessToken = null;

    public function __construct(string $suitecrm_base_url = '',
    string $suitecrm_client_id = '',
    string $suitecrm_client_secret = '',
    string $suitecrm_username = '',
    string $suitecrm_password = '')
    {
        // Load from environment variables
        $this->baseUrl      = 'https://acaventportal.com/legacy/Api/';
        $this->clientId     = '4d18f246-85e5-7417-2312-68bd1303752f';
        $this->clientSecret = '12121212';
        $this->username     = 'admin';
        $this->password     = 'admin';
    }

    /**
     * Check if SuiteCRM integration is enabled (credentials are set)
     */
    public function isEnabled(): bool
    {
        return !empty($this->baseUrl)
            && !empty($this->clientId)
            && !empty($this->clientSecret)
            && !empty($this->username)
            && !empty($this->password);
    }

    /**
     * Authenticate and get access token from SuiteCRM using password grant type
     */
    private function authenticate(): bool
    {

        if (!$this->isEnabled()) {
            return false;
        }

        $url = $this->baseUrl . 'access_token';

        $payload = [
            'grant_type'    => 'password',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $this->username,
            'password'      => $this->password,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode >= 200 && $statusCode < 300 && $response) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new Email record in SuiteCRM
     *
     * @param array $emailData Email data to create
     * @return array [success(bool), emailId(string|null), error(string|null)]
     */
    public function createEmailRecord(array $emailData): array
    {
        if (!$this->isEnabled()) {
            return [false, null, 'SuiteCRM integration not configured'];
        }

        if (!$this->authenticate()) {
            return [false, null, 'Failed to authenticate with SuiteCRM'];
        }

        $url = $this->baseUrl . 'V8/module';

        $payload = [
            'data' => [
                'type'       => 'Emails',
                'attributes' => $emailData,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/vnd.api+json',
                'Accept: application/vnd.api+json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return [false, null, 'cURL error: ' . curl_error($ch)];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $decoded = json_decode((string) $response, true);
            $errorMsg = 'HTTP ' . $statusCode;
            if (is_array($decoded) && !empty($decoded['errors'])) {
                $errorMsg .= ': ' . json_encode($decoded['errors']);
            }
            return [false, null, $errorMsg];
        }

        $decoded = json_decode((string) $response, true);
        $emailId = $decoded['data']['id'] ?? null;

        if (!$emailId) {
            return [false, null, 'No email ID returned from SuiteCRM'];
        }

        return [true, $emailId, null];
    }

    /**
     * Update an existing Email record in SuiteCRM
     *
     * @param string $emailId SuiteCRM email ID
     * @param array $updateData Data to update
     * @return array [success(bool), error(string|null)]
     */
    public function updateEmailRecord(string $emailId, array $updateData): array
    {
        if (!$this->isEnabled()) {
            return [false, 'SuiteCRM integration not configured'];
        }

        if (!$this->authenticate()) {
            return [false, 'Failed to authenticate with SuiteCRM'];
        }

        $url = $this->baseUrl . 'V8/module';

        $payload = [
            'data' => [
                'type'       => 'Emails',
                'id'         => $emailId,
                'attributes' => $updateData,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/vnd.api+json',
                'Accept: application/vnd.api+json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return [false, 'cURL error: ' . curl_error($ch)];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $decoded = json_decode((string) $response, true);
            $errorMsg = 'HTTP ' . $statusCode;
            if (is_array($decoded) && !empty($decoded['errors'])) {
                $errorMsg .= ': ' . json_encode($decoded['errors']);
            }
            return [false, $errorMsg];
        }

        return [true, null];
    }


     /**
     * Update an existing Email record in SuiteCRM by postmark id
     *
     * @param string $emailId Postmark email ID
     * @param array $updateData Data to update
     * @return array [success(bool), error(string|null)]
     */
    public function updateEmailRecordByPostmarkId(string $emailId, array $updateData): array
    {
        if (!$this->isEnabled()) {
            return [false, 'SuiteCRM integration not configured'];
        }

        if (!$this->authenticate()) {
            return [false, 'Failed to authenticate with SuiteCRM'];
        }

        $url = $this->baseUrl . 'V8/module';

        $payload = [
            'data' => [
                'type'              => 'Emails',
                'id'     =>          $this->getEmailRecordByPostmarkId($emailId),
                'attributes'        => $updateData,
            ],
        ];

        //  @file_put_contents(__DIR__ . '/postmark_debug.log', $payload, FILE_APPEND);

       

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/vnd.api+json',
                'Accept: application/vnd.api+json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response   = curl_exec($ch);
        // @file_put_contents(__DIR__ . '/postmark_debug.log', $response, FILE_APPEND);
      
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return [false, 'cURL error: ' . curl_error($ch)];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $decoded = json_decode((string) $response, true);
            $errorMsg = 'HTTP ' . $statusCode;
            if (is_array($decoded) && !empty($decoded['errors'])) {
                $errorMsg .= ': ' . json_encode($decoded['errors']);
            }
            return [false, $errorMsg];
        }

        return [true, null];
    }



    public function getEmailRecordByPostmarkId(string $emailId)
    {
        if (!$this->isEnabled()) {
            return [false, 'SuiteCRM integration not configured'];
        }

        $curl = curl_init();

            curl_setopt_array($curl, array(
            CURLOPT_URL => $this->baseUrl.'V8/module/Emails?filter[postmark_id_c][eq]='.$emailId,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer '.$this->accessToken,
            ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response, true);
            if (isset($data['data']) && count($data['data']) > 0) {
                // دریافت اولین آیتم از داده‌ها
                // @file_put_contents(__DIR__ . '/postmark_debug.log', "ID: ".$data['data'][0]['id'], FILE_APPEND);
                return $data['data'][0]['id'];
            }


    
 
    }


}
