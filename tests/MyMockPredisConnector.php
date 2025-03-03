<?php
namespace Padosoft\Laravel\Settings\Test;

use Illuminate\Redis\Connectors\PredisConnector;
use Illuminate\Support\Arr;
use M6Web\Component\RedisMock\RedisMockFactory;

class MyMockPredisConnector extends PredisConnector
{

    /**
     * Create a new clustered Predis connection.
     *
     * @param array $config
     * @param array $options
     *
     * @return \Illuminate\Redis\Connections\PredisConnection
     */
    public function connect(array $config, array $options)
    {
        $formattedOptions = array_merge(
            ['timeout' => 10.0], $options, Arr::pull($config, 'options', [])
        );

        $factory = new RedisMockFactory();
        $redisMockClass = $factory->getAdapter('Predis\Client', true, true, $config['host'] );
        return new \Lunaweb\RedisMock\MockPredisConnection($redisMockClass);
    }

    /**
     * Create a new clustered Predis connection.
     *
     * @param array $config
     * @param array $clusterOptions
     * @param array $options
     *
     * @return \Illuminate\Redis\Connections\PredisClusterConnection
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options)
    {
        $clusterSpecificOptions = Arr::pull($config, 'options', []);

        $factory = new RedisMockFactory();
        $redisMockClass = $factory->getAdapter('Predis\Client', true);

        return new \Lunaweb\RedisMock\MockPredisConnection(new $redisMockClass(array_values($config), array_merge(
            $options, $clusterOptions, $clusterSpecificOptions
        )));
    }

}
