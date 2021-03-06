<?php 

namespace Console;

if ( !$console->isInitialized() ) {
    return;
}

use \Symfony\Component\Console\Command\Command;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Input\InputArgument;
use \Symfony\Component\Console\Input\InputOption;
use \Symfony\Component\Console\Output\OutputInterface;

require_once 'vendor/nooku/libraries/koowa/event/subscriber/interface.php';
require_once 'vendor/nooku/libraries/koowa/object/handlable.php';


class Migrators implements \IteratorAggregate,\KEventSubscriberInterface , \KObjectHandlable
{
    protected $_migrators = array();
    
    protected $_event_dispatcher;
    
    protected $_output;
    
    protected $_console;
    
    public function __construct($console, $components, $check_max_version = true)
    {
        $this->_console = $console;
        
        $console->loadFramework();
        
        $components = array_map(function($item){
            return 'com_'.str_replace('com_','',$item);
        }, $components);      

        $paths = new DirectoryFilter($components, 
                array(WWW_ROOT.'/administrator/components'));
        
        $this->_event_dispatcher = \KService::get('koowa:event.dispatcher');
        
        foreach($paths as $path) 
        {
            $component  = str_replace('com_','', basename($path));
            $identifier = 'com://admin/'.$component.'.schema.migration';
            register_default(array('identifier'=>$identifier,'default'=>'ComMigratorMigrationDefault'));
            $migrator   = \KService::get($identifier, 
                        array('event_dispatcher'=>$this->_event_dispatcher));
            if ( ($check_max_version && $migrator->getMaxVersion() > 0) 
                    || !$check_max_version ) {
                $this->_migrators[] = $migrator;
            }
        }
        
        $this->_event_dispatcher
            ->addEventListener('onAfterSchemaMigration',    $this);
    }
    
    public function setOutput($output)
    {
        $this->_event_dispatcher
            ->addEventListener('onBeforeSchemaVersionUp',   $this)
            ->addEventListener('onBeforeSchemaVersionDown', $this)        
            ->addEventListener('onBeforeSchemaMigration',   $this)
        ;        
        $this->_output = $output;    
    }
    
    public function getEventDispatcher()
    {
         return $this->_event_dispatcher;   
    }
    
    public function getIterator()
    {
        return new \ArrayIterator($this->_migrators);
    }
    
    /**
     * (non-PHPdoc)
     * @see KObjectHandlable::getHandle()
     */
    public function getHandle() {return spl_object_hash($this); }
    
    /**
     * (non-PHPdoc)
     * @see KEventSubscriberInterface::getPriority()
     */
    public function getPriority() { return 0; }
    
    /**
     * (non-PHPdoc)
     * @see KEventSubscriberInterface::getSubscriptions()
     */
    public function getSubscriptions() { return array(); }    
    
    /**
     * 
     * @param \KEvent $event
     */
    public function onBeforeSchemaVersionUp(\KEvent $event)
    {
        $name = $event->caller->getComponent();
        $this->_output->writeLn('<info>Migrating up '.$name.' version '.$event->version.'</info>');
    }

    /**
     * 
     * @param \KEvent $event
     */
    public function onBeforeSchemaVersionDown(\KEvent $event)
    {
        $name = $event->caller->getComponent();
        $this->_output->writeLn('<info>Rolling back '.$name.' version '.$event->version.'</info>');
    }

    /**
     *
     * @param \KEvent $event
     */
    public function onBeforeSchemaMigration(\KEvent $event)
    {
        if ( !count($event->versions) ) {
            $name = $event->caller->getComponent();
            $this->_output->writeLn('<info>There are no migrations to run for '.$name.'</info>');
        }
    }

    /**
     * 
     * @param \KEvent $event
     */
    public function onAfterSchemaMigration(\KEvent $event)
    {
        if ( $event->caller->getComponent() == 'anahita' )
        {
            $path = ANAHITA_ROOT.'/vendor/joomla/installation/sql';            
            $event->caller->setOutputPath($path);
        }
        $tables          = $event->caller->getTables();
        $schema_file     = $event->caller->getOutputPath().'/schema.sql';
        $uninstall_file  = $event->caller->getOutputPath().'/uninstall.sql';      
        $schema          = fopen($schema_file, 'w');
        $uninstall       = fopen($uninstall_file, 'w');
        $dump = new \MySQLDump($event->caller->getDatabaseAdapter()->getConnection());
        $prefix_replace = array();        
        foreach($tables as $table) 
        {
            $prefix_replace[$table] = str_replace($event->caller
                            ->getDatabaseAdapter()->getTablePrefix(),'#__', $table);
            $dump->tables[$table] = \MySQLDump::CREATE; 
            $dump->dumpTable($schema, $table);
            $dump->tables[$table] = \MySQLDump::DROP;
            $dump->dumpTable($uninstall, $table);
        }
        
        $version     = $event->caller->getCurrentVersion();
        $component   = $event->caller->getComponent();
        fwrite($schema, "INSERT INTO #__migrator_versions (`version`,`component`) ".
            "VALUES($version, '$component') ON DUPLICATE KEY UPDATE `version` = $version;");
        
        
        fwrite($uninstall, "DELETE #__migrator_versions  WHERE `component` = '$component';");
        
        fclose($schema);
        fclose($uninstall);

        
        //fix the prefix
        foreach(array($schema_file, $uninstall_file) as $file) 
        {
            $content    = file_get_contents($file);
            $content    = str_replace(array_keys($prefix_replace),
                    array_values($prefix_replace), $content);
            file_put_contents($file, $content);            
        }
 
        $content = file_get_contents($schema_file);
        $replace = array(
            '/ TYPE=/' => ' ENGINE=',
            '/ AUTO_INCREMENT=\w+/' => ''
         );
        //fix the auto increment
        $content = preg_replace( 
                array_keys($replace),
                array_values($replace),
                file_get_contents($schema_file)
        );
        file_put_contents($schema_file, $content); 

        //delete uninsall file for anahita
        if ( $event->caller->getComponent() == 'anahita' ) {
            unlink($uninstall_file);
        }        
    }   
}

$console
->register('db:migrate:up')
->setDescription('Run the database migration for a component')
->setDefinition(array(
        new InputArgument('component', InputArgument::IS_ARRAY, 'Name of the components'),
))
->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {
    
    $components = $input->getArgument('component');
    if ( empty($components) )
    {
        $dirs       = new \DirectoryIterator(WWW_ROOT.'/administrator/components');
        $components = array();
        foreach($dirs as $dir) {
            if ( $dir->isDir() && !$dir->isDot() )
                $components[] = basename($dir);
        }
    } else {
        $components = $input->getArgument('component'); 
    }
    
    $migrators  = new Migrators($console, $components);        

    $migrators->setOutput($output);
    foreach($migrators as $migrator) {
        $migrator->up();
    }
});
;

$console
->register('db:migrate:rollback')
->setDescription('Rollback the database migration for a component')
->setDefinition(array(
        new InputArgument('component', InputArgument::IS_ARRAY, 'Name of the components'),
))
->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {
    $migrators  = new Migrators($console,
            $input->getArgument('component'));

    $migrators->setOutput($output);
    foreach($migrators as $migrator) {
        $migrator->down();
    }
});

$console
    ->register('db:migrate:list')
    ->setDescription('list available migrations')
    ->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {
        $dirs       = new \DirectoryIterator(WWW_ROOT.'/administrator/components');
        $components = array();
        foreach($dirs as $dir) {
            if ( $dir->isDir() && !$dir->isDot() )
                $components[] = basename($dir);
        }
        $migrators  = new Migrators($console, $components);
            
        foreach($migrators as $migrator)
        {
            $component = $migrator->getComponent();
            $text = 'version '.$migrator->getCurrentVersion();
            if ( $behind = $migrator->getVersionsBehind() > 0 ) {
                $text .= ' behind '.$behind;
            }
            $output->writeLn('<info>'.$component.'</info> '.$text);            
        }
    });
    
$console
        ->register('db:generate:migration')
        ->setDescription('Generate a migration for a component')
        ->setDefinition(array(
                new InputArgument('component', InputArgument::IS_ARRAY, 'Name of the components'),                
        ))
        ->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {
            $migrators  = new Migrators($console,
                    $input->getArgument('component'),false);
            
            $migrators->setOutput($output);
            foreach($migrators as $migrator) {
                $migrator->generateMigration();                
            }
        });    

$console
    ->register('db:schema:dump')
    ->setDescription('Dumps the schema for a component into its schema.sql file')
    ->setDefinition(array(
            new InputArgument('component', InputArgument::IS_ARRAY, 'Name of the components'),
    ))
    ->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {
        $migrators  = new Migrators($console,
                $input->getArgument('component'));
    
        $migrators->setOutput($output);
        foreach($migrators as $migrator) 
        {
            $migrator->createSchema();
            $migrator->write();
            $output->writeLn('<info>Dump database schema for com_'.$migrator->getComponent().'</info>');
        }
    });
    
$console
        ->register('db:dump')
        ->setDescription('Dump data to a sql file')
        ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'The output file'),
        ))
        ->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {
                $file = $input->getArgument('file');
                $console->loadFramework();
                $config = new \JConfig();
                $cmd    = "mysqldump -u {$config->user} -p{$config->password} -h{$config->host} {$config->db}";
                if  ($file)  {
                    @mkdir(dirname($file), 0755, true);
                    system("$cmd > $file");
                } else {
                    passthru($cmd);
                }
        });
        
$console
            ->register('db:load')
            ->setDescription('Load data from a sql file into the database')
            ->setDefinition(array(
                    new InputArgument('file', InputArgument::REQUIRED, 'The output file'),
                    new InputOption('drop-tables','', InputOption::VALUE_NONE, 'If all the tables are droped first'),
            ))
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {                
                $file = $input->getArgument('file');
                if ( !file_exists($file) ) {
                    throw new \Exception("File '$file' doesn't exists");
                }
                $console->loadFramework();
                if ( $input->getOption('drop-tables') ) 
                {
                    $db = \KService::get('koowa:database.adapter.mysqli');
                    $tables = $db->select('SHOW TABLES', \KDatabase::FETCH_FIELD_LIST);
                    $output->writeLn('Dropping tables...');
                    foreach($tables as $table) {
                        $db->execute('DROP TABLE '.$table);
                    }                    
                }
                $config     = new Config(WWW_ROOT);
                $config     = new \KConfig($config->getDatabaseInfo());
                $output->writeLn('Loading data. This may take a while...');
                system("mysql -u {$config->user} -p{$config->password} -h{$config->host} -P{$config->port} {$config->name} < $file");                
            });

?>