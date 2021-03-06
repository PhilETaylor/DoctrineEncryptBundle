<?php
/*
 * @copyright  Copyright (C) 2017, 2018, 2019 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/mysites.guru
 * @license    MIT
 */

namespace Philetaylor\DoctrineEncryptBundle\Command;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Decrypt whole database on tables which are encrypted.
 *
 * @author Marcel van Nuil <marcel@ambta.com>
 * @author Michael Feinbier <michael@feinbier.net>
 */
class DoctrineDecryptDatabaseCommand extends PhiletaylorAbstract
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('doctrine:decrypt:database')
            ->setDescription('Decrypt whole database on tables which are encrypted')
            ->addOption('entity', null, InputOption::VALUE_OPTIONAL, 'The entity to decrypt', null)
            ->addArgument('batchSize', InputArgument::OPTIONAL, 'The update/flush batch size', 20);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        ini_set('memory_limit', '1024M');

        //Get entity manager, question helper, subscriber service and annotation reader
        $question = $this->getHelper('question');

        //Get list of supported encryptors
        $batchSize    = $input->getArgument('batchSize');
        $entityFilter = $input->getOption('entity', null);

        //Get entity manager metadata
        $metaDataArray = $this->entityManager->getMetadataFactory()->getAllMetadata();

        //Set counter and loop through entity manager meta data
        $propertyCount = 0;
        foreach ($metaDataArray as $metaData) {
            if ($metaData->isMappedSuperclass) {
                continue;
            }

            $countProperties = \count($this->getEncryptionableProperties($metaData));
            $propertyCount += $countProperties;
        }

        $confirmationQuestion = new ConfirmationQuestion(
            "<question>\n".\count($metaDataArray).' entities found which are containing '.$propertyCount." properties with the encryption tag. \n\n".
            'Which are going to be decrypted with ['.$this->subscriber->getEncryptor()."]. \n\n".
            "Wrong settings can mess up your data and it will be unrecoverable. \n".
            "I advise you to make <bg=yellow;options=bold>a backup</bg=yellow;options=bold>. \n\n".
            'Continue with this action? (y/yes)</question>', false
        );

        if (!$question->ask($input, $output, $confirmationQuestion)) {
            return self::FAILURE;
        }

        //Start decrypting database
        $output->writeln("\nDecrypting all fields. This can take up to several minutes depending on the database size.");

        $valueCounter = 0;

        \define('_DONOTENCRYPT', true);

        //Loop through entity manager meta data
        foreach ($this->getEncryptionableEntityMetaData() as $metaData) {
            if ($entityFilter && str_replace('\\', '', $metaData->name) != $entityFilter) {
                $output->writeln('Skipping '.$metaData->name.' as you only want me to process '.$entityFilter);
                continue;
            }

            $i          = 0;
            $iterator   = $this->getEntityIterator($metaData->name);
            $totalCount = $this->getTableCount($metaData->name);

            $output->writeln(sprintf('Processing <comment>%s</comment>', $metaData->name));
            $progressBar = new ProgressBar($output, $totalCount);
            foreach ($iterator as $row) {
                $entity = $row[0];

                //Create reflectionClass for each entity
                $entityReflectionClass = new \ReflectionClass($entity);

                //Get the current encryptor used
                $encryptorUsed = $this->subscriber->getEncryptor();

                //Loop through the property's in the entity
                foreach ($this->getEncryptionableProperties($metaData) as $property) {
                    //Get and check getters and setters
                    $methodeName = ucfirst($property->getName());

                    $getter = 'get'.$methodeName;
                    $setter = 'set'.$methodeName;

                    //Check if getter and setter are set
                    if ($entityReflectionClass->hasMethod($getter) && $entityReflectionClass->hasMethod($setter)) {
                        //Get decrypted data
                        $unencrypted = $entity->$getter();

                        if (strpos($unencrypted, '<Ha>')) {
                            //Set raw data
                            $entity->$setter($unencrypted);

                            ++$valueCounter;
                        }
                    }
                }

                //Disable the encryptor
                $this->subscriber->setEncryptor(null);

                if ($valueCounter) {
                    $this->entityManager->persist($entity);

                    if (0 === ($i % $batchSize)) {
                        $this->entityManager->flush();
                    }
                }
                $progressBar->advance(1);
                ++$i;

                //Set the encryptor again
                $this->subscriber->setEncryptor($encryptorUsed);
            }

            $progressBar->finish();
            $output->writeln('');
            //Get the current encryptor used
            $encryptorUsed = $this->subscriber->getEncryptor();
            $this->subscriber->setEncryptor(null);
            $this->entityManager->flush();
            $this->entityManager->clear();
            //Set the encryptor again
            $this->subscriber->setEncryptor($encryptorUsed);
        }

        //Say it is finished
        $output->writeln("\nDecryption finished values found: <info>".$valueCounter.'</info>, decrypted: <info>'.$this->subscriber->decryptCounter."</info>.\nAll values are now decrypted.");

        return self::SUCCESS;
    }
}
