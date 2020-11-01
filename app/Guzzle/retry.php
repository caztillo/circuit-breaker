<?php 

namespace App\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Carbon\Carbon;
use RuntimeException;

class GuzzleRetry {

	/**
     * RateLimiter instance
     *
     */
	private $limiter;


    /**
     * Key of the service making the call
     *
     * @var string
     */
    private $service;


    /**
     * The Circuit Breaker configuration
     */
    private $config = [
        'retries' => 3,
        'delayMiliseconds' => 1000,
        'timeoutSeconds' => 2000,
        'failures' => 2,
        'thresholdMinutes' => 5
    ];



     /**
     * Class contructor
     *
     * @param string $service
     * @param RateLimiter $limiter
     * @param array $config
     * @param function $failureCallback
     */

    function __construct($service, $limiter, $config = [])
	{
		$this->limiter = $limiter;
		$this->service = $service;
		$this->config = array_merge($this->config, $config);
	}


	/**
     * Create function
     *
     * @return GuzzleHttp client instance with retry middleware.
     */
	public function create()
	{

		$handler = new CurlHandler();
		$stack  = HandlerStack::create($handler);
		$stack->push(Middleware::retry($this->decider(), $this->delay()));


		$client = new Client(['handler' => $stack , 'timeout' => $this->config['timeoutSeconds'], 'http_errors' => true]);

		return $client;
	}


	/**
     * Decider function
     *
     * @param callable $decider Function that accepts the number of retries,
     *                          a request, [response], and [exception] and
     *                          returns true if the request is to be retried.
     *
     * @return callable Returns a function that accepts the next handler.
     */
	private function decider()
	{
		return function (
			$retries,
			Request $request,
			Response $response = null,
			RequestException $exception = null
		) {
			

			if ($retries >= $this->config['retries']) 
			{
				\Log::info("Max retries reached");
				$this->limiter->hit($this->service, Carbon::now()->addMinutes($this->config['thresholdMinutes']));
				return false;
			}

			if ($exception instanceof ConnectException) 
			{
				\Log::info("ConnectException");
				$this->limiter->hit($this->service, Carbon::now()->addMinutes($this->config['thresholdMinutes']));
				return true;
			}

			if ($exception instanceof RequestException) 
			{
				\Log::info("RequestException");
				$this->limiter->hit($this->service, Carbon::now()->addMinutes($this->config['thresholdMinutes']));
				return true;
			}

			if ($response) 
			{
				$json = json_decode($response->getBody());

				if(isset($json->completed))
				{
					if(!$json->completed)
					{
						
						\Log::info("Incomplete");
						return true;
					}
					
					
				}

				

				if ($response->getStatusCode() >= 500) 
				{
					\Log::info("http 500 errors");
					$this->limiter->hit($this->service, Carbon::now()->addMinutes($this->config['thresholdMinutes']));
					return true;
				}
			}

			return false;
		};
	}


	/**
     * Delay function
     *
     * @param callable $delay   Function that accepts the number of retries and
     *                          returns the number of milliseconds to delay.
     *
     * @return callable Returns a function that accepts the next handler.
     */
	private function delay()
	{
		return function ($numberOfRetries) 
		{
			return $this->config['delayMiliseconds'] * $numberOfRetries;
		};
	}



    

}