<?php

namespace whitelabeled\DaisyconApi;

use DateTime;
use Httpful\Request;

class DaisyconClient {
    private $username;
    private $password;
    private $token;

    protected $publisherId;
    protected $endpoint = 'https://services.daisycon.com';
    protected $itemsPerPage = 200;

    /**
     * @var boolean Enable revenue share processing
     */
    public $revShareEnabled = false;

    /**
     * @var array Restrict to media ID's
     */
    public $mediaIds = [];

    /**
     * DaisyconClient constructor.
     * @param $username    string Daisycon username
     * @param $password    string Password
     * @param $publisherId string Publisher ID
     * @throws DaisyconApiException
     */
    public function __construct($username, $password, $publisherId) {
        $this->username = $username;
        $this->password = $password;
        $this->publisherId = $publisherId;

        $this->generateToken();
    }

    /**
     * Generates an Auth token
     * @throws DaisyconApiException
     */
    private function generateToken() {
        $request = Request::post($this->endpoint . '/authenticate')
            ->sendsJson()
            ->body(['username' => $this->username, 'password' => $this->password]);

        $response = $request->send();

        if ($response->hasErrors()) {
            $this->token = null;
            throw new DaisyconApiException('Auth failure');
        }

        $this->token = $response->body;
    }

    /**
     * Get a token (or generate it)
     * @return string
     * @throws DaisyconApiException
     */
    private function getToken() {
        if ($this->token == null) {
            $this->generateToken();
        }

        return $this->token;
    }

    /**
     * Get all transactions from $startDate until $endDate.
     *
     * @param DateTime      $startDate Start date
     * @param DateTime|null $endDate   End date, optional.
     * @param int           $page      Page, optional. Pagination starts with page=1
     * @return array Transaction objects. Each part of a transaction is returned as a separate Transaction.
     * @throws DaisyconApiException
     */
    public function getTransactions(DateTime $startDate, DateTime $endDate = null, $page = 1) {
        $params = [
            'page'                => $page,
            'per_page'            => $this->itemsPerPage,
            'date_modified_start' => $startDate->format('Y-m-d H:i:s'),
        ];

        if ($this->mediaIds != null && count($this->mediaIds) > 0) {
            $params['media_id'] = join(',', $this->mediaIds);
        }

        if ($endDate != null) {
            $params['date_modified_end'] = $endDate->format('Y-m-d H:i:s');
        }

        $query = '?' . http_build_query($params);
        $response = $this->makeRequest("/publishers/{$this->publisherId}/transactions", $query);

        $transCounter = 0;
        $transactions = [];
        $transactionsData = $response->body;

        if ($transactionsData != null) {
            foreach ($transactionsData as $transactionData) {
                foreach ($transactionData->parts as $transPart) {
                    $transaction = Transaction::createFromJson($transactionData, $transPart, $this->revShareEnabled);
                    $transactions[] = $transaction;
                }

                $transCounter++;
            }
        }

        // Check whether more iterations are needed:
        $totalItems = $response->headers['x-total-count'];
        $currentPageTotal = $transCounter + $this->itemsPerPage * ($page - 1);

        // Retrieve more items when
        if ($totalItems > $currentPageTotal) {
            $transactions = array_merge($transactions, $this->getTransactions($startDate, $endDate, $page + 1));
        }

        return $transactions;
    }

    /**
     * @param        $resource
     * @param string $query
     * @return mixed
     * @throws DaisyconApiException
     */
    protected function makeRequest($resource, $query = "") {
        $uri = $this->endpoint . $resource;

        $request = Request::get($uri . $query)
            ->addHeader('Authorization', 'Bearer ' . $this->getToken())
            ->expectsJson();

        $response = $request->send();

        // Check for errors
        if ($response->hasErrors()) {
            throw new DaisyconApiException('Invalid data');
        }

        return $response;
    }
}
