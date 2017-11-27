<?php
/**
 * Created by PhpStorm.
 * User: many
 * Date: 2017/11/25
 * Time: 13:56
 */

namespace Phphc\Gearman\Jobs;

use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Jobs\JobName;
use GearmanWorker;
use Exception;
use Illuminate\Contracts\Queue\Job as QueueJobInterface;
use Log;

class GearmanJob extends Job implements QueueJobInterface
{
    protected $worker;
    protected $job;
    protected $rawPayload = '';
    private $maxRunTime = 1;
    private $single = false;

    public function __construct(Container $container, GearmanWorker $worker, $queue)
    {
        $this->container = $container;
        $this->worker = $worker;
        Log::info($queue);
        $this->worker->addFunction($queue, array($this, 'onGearmanJob'));
    }

    public function fire()
    {
        $startTime = time();
        while ($this->worker->work() || $this->worker->returnCode() == GEARMAN_TIMEOUT) {
            // Check for expiry.
            if ((time() - $startTime) >= 60 * $this->maxRunTime) {
                echo sprintf('%s minutes have elapsed, expiring.', $this->maxRunTime) . PHP_EOL;
                break;
            }
        }
    }

    public function delete()
    {
        parent::delete();
    }

    public function release($delay = 0)
    {
        if ($delay > 0) {
            throw new Exception('No delay is suported');
        }
    }

    public function attempts()
    {
        return 1;
    }

    public function getJobId()
    {
        return base64_encode($this->job);
    }

    public function getContainer()
    {
        return $this->container;
    }

    public function getGearmanWorker()
    {
        return $this->worker;
    }

    public function onGearmanJob(\GearmanJob $job)
    {
        $this->rawPayload = $job->workload();

        $payload = json_decode($this->rawPayload, true);
        if (method_exists($this, 'resolveAndFire')) {
            $this->resolveAndFire($payload);
            return;
        }
        // compatibility with Laravel 5.4+
        if (class_exists(JobName::class)) {
            list($class, $method) = JobName::parse($payload['job']);
        } else {
            list($class, $method) = $this->parseJob($payload['job']);
        }
        $this->instance = $this->resolve($class);
        $name=$this->instance->{$method}($this, $payload['data']);
        return $name;
    }

    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return $this->rawPayload;
    }
}