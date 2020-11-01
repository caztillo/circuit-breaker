<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use App\Guzzle\GuzzleRetry;
use Illuminate\Cache\RateLimiter;
use Carbon\Carbon;


class SendPost extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'post:request';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simple resilient request';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $limiter = app(RateLimiter::class);

        $service = "fakepost";


        $config = [
            'retries' => 3,
            'delayMiliseconds' => 1000,
            'timeoutSeconds' => 4000,
            'failures' => 2,
            'thresholdMinutes' => 5
            ];


        

        try 
        {
            if ($limiter->tooManyAttempts($service, $config['failures']))
            {
                return $this->failOrFallback();
            }

            $client = (new GuzzleRetry($service,$limiter,$config )  )->create();

            $response = $client->get('https://atomic.incfile.com/fakepost');
            //$response = $client->get('https://jsonplaceholder.typicode.com/todos/1');

            $result = $response->getBody()->getContents();


            $this->info($result);
        
        } 
        catch (\Exception $exception) 
        {
            $limiter->hit($service, Carbon::now()->addMinutes($config['thresholdMinutes']));
        }
            

        
    }


    private function failOrFallback()
    {
        return \Log::info("failOrFallback logic");
    }



    

}
