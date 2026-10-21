<?php

namespace App\DocumentManagement\Application\Commands\GenerateReport;

use App\DocumentManagement\Domain\Repository\FiledRepository;
use DateTime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:generate-report')]
class GenerateReportCommand extends Command
{
    public function __construct(private readonly FiledRepository $repository, private string $time_start, private string $time_end, private string $difference_days)
    {
        parent::__construct('app:generate-report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delimiter = '|&';
        try {
            $this->time_start = $input->getArgument('time_start') ? $input->getArgument('time_start') : $this->time_start;
            $this->time_end = $input->getArgument('time_end') ? $input->getArgument('time_end') : $this->time_end;
            $this->difference_days = $input->getArgument('difference_days') !== null ? $input->getArgument('difference_days') : $this->difference_days;

            $output->writeln('[' . date('Y-m-d H:i:s') . '] -- Inicio job para generar el plano con las guias recibidas --');
            $filed = $this->repository->getDocumentsByDateAndInPlane($this->time_start, $this->time_end, $this->difference_days);
            $headers = [
                'NumRadicado', 'CodGuia', 'RutaArchivo', 'TipoDocumento', 'Documento', 'Celular', 'NumTramite',
                'CodDane', 'Direccion', 'NombreCompleto', 'Telefono', 'Prioridad', 'Impreso', 'PortePago', 'TipoPortePago',
                'TipoProceso', 'RadicadoCasoPadre', 'UsuarioSolicitante', 'FechaDeCreacion'
            ];
            $content = implode($delimiter, $headers).PHP_EOL;
            $documentIdsInPlane = [];
            foreach ($filed as $item) {
                $documents = $item['documents'];

                if (count($documents) == 0) {
                    //$content .= $this->createTxtForFile($item, $delimiter, 'Error en descarga'.$delimiter);
                    continue;
                }

                foreach ($documents as $document) {
                    if (!isset($document['ruta']) || $document['ruta'] == '') {
                        continue;
                    }
                    $documentIdsInPlane[] = $document['id_documento'];
                    $content .= $this->createTxtForFile($item, $delimiter, $document['id_gestor_documento'].'.pdf'.$delimiter);
                }
            }

            $date = new DateTime();
            $fp = fopen("public/planos/radicados-".$date->format('Y-m-d').'.txt',"w");
            fwrite($fp,$content);
            fclose($fp);

            // Se marca como reportado solo despues de escribir el plano.
            foreach ($documentIdsInPlane as $documentId) {
                $this->repository->updateInPlane($documentId);
            }
            $output->writeln('[' . date('Y-m-d H:i:s') . '] -- Fin job para generar el plano con las guias recibidas --');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
            $output->writeln('[' . date('Y-m-d H:i:s') . '] -- Fin con error del job para generar el plano con las guias recibidas --');
            return Command::FAILURE;
        }
    }

    private function createTxtForFile(array $item, string $delimiter, string $routeDocument): string {
        $content = $item['num_radicado'].$delimiter;
        $content .= $item['codigo_guia'].$delimiter;
        $content .= "public/files/".$routeDocument;
        if (isset($item['identification']) && count($item['identification']) > 0) {
            $content .= $item['identification']['tipo_documento'].$delimiter;
            $content .= $item['identification']['documento'].$delimiter;
        } else {
            $content .= $delimiter;
            $content .= $delimiter;
        }

        unset(
            $item['id_radicado'], $item['fk_identificacion'], $item['num_radicado'], $item['identification'],
            $item['documents'], $item['codigo_guia'], $item['guia_impresa'], $item['event_name'], $item['id_case']
        );
        $content .= implode($delimiter, $item).PHP_EOL;

        return $content;
    }

    protected function configure()
    {
        $this
            ->addArgument('time_start', InputArgument::OPTIONAL, 'What time to start?')
            ->addArgument('time_end', InputArgument::OPTIONAL, 'What time end?')
            ->addArgument('difference_days', InputArgument::OPTIONAL, 'How many days ago?')
        ;
    }
}