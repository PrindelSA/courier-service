<?php

namespace App\DocumentManagement\Infrastructure\Repository;

use App\DocumentManagement\Domain\Entity\Document;
use App\DocumentManagement\Domain\Entity\Filed;
use App\DocumentManagement\Domain\Repository\DocumentRepository;
use App\DocumentManagement\Domain\ResponsePaginator;
use App\Shared\Domain\Exceptions\DocumentNotExistException;
use App\Shared\Domain\Exceptions\ExistException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class DocumentMysqlRepository extends ServiceEntityRepository implements DocumentRepository
{
    const PAGE_LIMIT = 10;

    public function __construct(private ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }


    /**
     * @throws ExistException
     */
    function create(Document $document, Filed $filed): Document
    {
        $document->setFiled($filed);
        $this->registry->getManager()->persist($document);
        $this->registry->getManager()->flush();

        return $document;
    }

    /**
     * @throws DocumentNotExistException
     */
    function updatePathFile(string $documentId, string $guideNumber): void
    {
        $filed = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f')
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'f')
            ->where('f.codigo_guia = :guideNumber')
            ->setParameter('guideNumber', $guideNumber)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!isset($filed)) {
            throw new DocumentNotExistException('El radicado de este documento no existe.');
        }

        // A filing may carry the same document id more than once; the file path depends only
        // on the id, so every matching row must be updated or the duplicates stay without route.
        $documents = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('d')
            ->from('App\DocumentManagement\Domain\Entity\Document', 'd')
            ->where('d.id_gestor_documento = :documentId')
            ->andWhere('d.fk_radicado = :filedId')
            ->setParameter('documentId', $documentId)
            ->setParameter('filedId', $filed->getIdRadicado())
            ->getQuery()
            ->getResult();

        if (empty($documents)) {
            throw new DocumentNotExistException('El documento no existe.');
        }

        foreach ($documents as $document) {
            $document->setRuta('files/'.$documentId.'.pdf');
            $this->registry->getManager()->persist($document);
        }
        $this->registry->getManager()->flush();
    }

    public function getAllDocuments(int $page, array $paramsToSearch): ResponsePaginator
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT d.id_documento FROM documento d WHERE 1=1";

        if (isset($paramsToSearch['id_documento']) && $paramsToSearch['id_documento'] != '') {
            $escapedTerm = $connection->quote('%' . $paramsToSearch['id_documento'] . '%');
            $sql .= " AND d.id_gestor_documento LIKE $escapedTerm";
        }

        if (isset($paramsToSearch['ruta']) && $paramsToSearch['ruta'] != '') {
            $escapedTerm = $connection->quote('%' . $paramsToSearch['ruta'] . '%');
            $sql .= " AND d.ruta LIKE $escapedTerm";
        }

        if (isset($paramsToSearch['created_at']) && $paramsToSearch['created_at'] != '') {
            $escapedTerm = $connection->quote('%' . $paramsToSearch['created_at'] . '%');
            $sql .= " AND d.created_at LIKE $escapedTerm";
        }

        $sql .= " ORDER BY d.id_documento DESC";

        $countSql = str_replace("SELECT d.id_documento", "SELECT COUNT(*) as total", $sql);
        $countResult = $connection->executeQuery($countSql);
        $totalItems = $countResult->fetchOne();

        $totalPages = ceil($totalItems / self::PAGE_LIMIT);

        $offset = ($page - 1) * self::PAGE_LIMIT;
        $sql .= " LIMIT " . self::PAGE_LIMIT . " OFFSET " . $offset;

        $result = $connection->executeQuery($sql);
        $pageIds = $result->fetchFirstColumn();

        if (!empty($pageIds)) {
            $queryBuilder = $this->getEntityManager()
                ->createQueryBuilder()
                ->select('d')
                ->from('App\DocumentManagement\Domain\Entity\Document', 'd')
                ->where('d.id_documento IN (:ids)')
                ->orderBy('d.id_documento', 'DESC')
                ->setParameter('ids', $pageIds);

            $dqlQuery = $queryBuilder->getQuery();
            $paginator = new Paginator($dqlQuery);
        } else {
            $dqlQuery = $this->getEntityManager()
                ->createQueryBuilder()
                ->select('d')
                ->from('App\DocumentManagement\Domain\Entity\Document', 'd')
                ->where('1=0')
                ->getQuery();

            $paginator = new Paginator($dqlQuery);
        }

        return new ResponsePaginator($paginator, $totalPages);
    }

    function getDocumentById(int $documentId): Document|null
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('d')
            ->from('App\DocumentManagement\Domain\Entity\Document', 'd')
            ->where('d.id_documento = :id')
            ->setParameter('id', $documentId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}