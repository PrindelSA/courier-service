<?php
namespace App\DocumentManagement\Application\Commands\ReDownloadFiles;

use App\DocumentManagement\Application\Commands\GetDocumentFile\GetDocumentFileCommand;
use App\DocumentManagement\Domain\Repository\FiledRepository;
use App\Shared\Domain\CommandBus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'app:redownload-files')]
class ReDownloadFilesCommand extends Command {

    public function __construct(private readonly FiledRepository $repository, private readonly CommandBus $commandBus)
    {
        parent::__construct('app:redownload-files');
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Dias hacia atras en los que buscar documentos sin descargar', 7);
    }

    protected function dispatch(\App\Shared\Domain\Command $command): void
    {
        $this->commandBus->dispatch($command);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('[' . date('Y-m-d H:i:s') . '] -- Inicio job para re-descargar los archivos --');
            $days = (int) $input->getArgument('days');
            $filed = $this->repository->getDocumentsWithoutRoute($days);
            $output->writeln(count($filed));

            $failed = 0;
            foreach ($filed as $item) {
                $documents = $item['documents'];
                foreach ($documents as $document) {
                    $output->writeln($item['num_radicado'].' - '.$document['id_gestor_documento']);
                    // Un documento que falla no debe frenar la descarga de los demas.
                    try {
                        $command = new GetDocumentFileCommand($document['id_gestor_documento'], $item['codigo_guia']);
                        $this->dispatch($command);
                    } catch (\Exception $e) {
                        $failed++;
                        $previous = $e;
                        while ($previous instanceof HandlerFailedException) {
                            $previous = $previous->getPrevious();
                        }
                        $output->writeln('ERROR '.$item['num_radicado'].' - '.$document['id_gestor_documento'].': '.$previous->getMessage());
                    }
                }
            }
            $output->writeln('[' . date('Y-m-d H:i:s') . '] -- Fin job para re-descargar los archivos (fallidos: '.$failed.') --');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
            $output->writeln('[' . date('Y-m-d H:i:s') . '] -- Fin con error del job para re-descargar los archivos --');
            return Command::FAILURE;
        }
    }
}