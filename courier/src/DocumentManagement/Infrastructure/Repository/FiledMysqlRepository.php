<?php

namespace App\DocumentManagement\Infrastructure\Repository;

use App\DocumentManagement\Domain\Entity\Filed;
use App\DocumentManagement\Domain\Entity\Identification;
use App\DocumentManagement\Domain\Repository\FiledRepository;
use App\DocumentManagement\Domain\ResponsePaginator;
use App\DocumentManagement\Domain\ValueObjects\FiledNumberValueObject;
use App\Shared\Domain\Exceptions\ExistException;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class FiledMysqlRepository extends ServiceEntityRepository implements FiledRepository
{
    const PAGE_LIMIT = 10;

    public function __construct(private ManagerRegistry $registry)
    {
        parent::__construct($registry, Filed::class);
    }


    /**
     * @throws ExistException
     */
    function create(Filed $filed, Identification $identification): Filed
    {
        $exist = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('r')
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'r')
            ->where('r.num_radicado = :filedNumber')
            ->orWhere('r.codigo_guia = :guideNumber')
            ->setParameter('filedNumber', $filed->getNumRadicado())
            ->setParameter('guideNumber', $filed->getCodigoGuia())
            ->getQuery()
            ->getOneOrNullResult();

        if (isset($exist)) {
            throw new ExistException('El numero de radicado o el codigo de guia ya existen');
        }

        $filed->setIdentification($identification);
        $this->registry->getManager()->persist($filed);
        $this->registry->getManager()->flush();

        return $filed;
    }

    /**
     * @param string $time_start
     * @param string $time_end
     * @param string $difference_days
     * @return Filed[]
     */
    function getDocuments(string $time_start, string $time_end, string $difference_days): array
    {
        $date = new DateTime();
        $queryDateStart = $date->format('Y-m-d') . ' ' . $time_start;
        if ($difference_days > 0) {
            $date->modify('-' . $difference_days . ' days');
            $queryDateStart = $date->format('Y-m-d') . ' ' . $time_start;
        }

        $dateEnd = new DateTime();
        $queryDateEnd = $dateEnd->format('Y-m-d') . ' ' . $time_end;

        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f', 'i', 'd')
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'f')
            ->where('f.created_at >= :date_start')
            ->andWhere('f.created_at <= :date_end')
            ->setParameter('date_start', $queryDateStart)
            ->setParameter('date_end', $queryDateEnd)
            ->leftJoin('f.identification', 'i')
            ->leftJoin('f.documents', 'd')
            ->getQuery()
            ->getArrayResult();
    }

    function getFiled(FiledNumberValueObject $filedNumberValueObject, bool $checkDocuments = false):  Filed|array|null
    {
        $filed = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('r')
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'r')
            ->where('r.num_radicado = :filedNumber')
            ->setParameter('filedNumber', $filedNumberValueObject->getValue())
            ->getQuery()
            ->getOneOrNullResult();

        if (!$filed) return null;
        if (!$checkDocuments) return $filed;

        $documents = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('d')
            ->from('App\DocumentManagement\Domain\Entity\Document', 'd')
            ->where('d.fk_radicado = :id_radicado')
            ->andWhere('d.ruta is null')
            ->setParameter('id_radicado', $filed->getIdRadicado())
            ->getQuery()
            ->getArrayResult();

        if (count($documents) > 0) return [$filed, $documents];

        return $filed;
    }

    function getAllFiled($page, $paramsToSearch): ResponsePaginator
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT r.id_radicado FROM radicado r WHERE 1=1";

        if (isset($paramsToSearch['num_radicado']) && $paramsToSearch['num_radicado'] != '') {
            $searchTerm = '*' . $paramsToSearch['num_radicado'] . '*';
            $escapedTerm = $connection->quote($searchTerm);
            $sql .= " AND MATCH(r.num_radicado) AGAINST($escapedTerm IN BOOLEAN MODE)";
        }

        if (isset($paramsToSearch['name']) && $paramsToSearch['name'] != '') {
            $searchTerm = '*' . $paramsToSearch['name'] . '*';
            $escapedTerm = $connection->quote($searchTerm);
            $sql .= " AND MATCH(r.nombre_completo) AGAINST($escapedTerm IN BOOLEAN MODE)";
        }

        if (isset($paramsToSearch['phone']) && $paramsToSearch['phone'] != '') {
            $searchTerm = '*' . $paramsToSearch['phone'] . '*';
            $escapedTerm = $connection->quote($searchTerm);
            $sql .= " AND MATCH(r.telefono) AGAINST($escapedTerm IN BOOLEAN MODE)";
        }

        if (isset($paramsToSearch['radicado_padre']) && $paramsToSearch['radicado_padre'] != '') {
            $searchTerm = '*' . $paramsToSearch['radicado_padre'] . '*';
            $escapedTerm = $connection->quote($searchTerm);
            $sql .= " AND MATCH(r.radicado_caso_padre) AGAINST($escapedTerm IN BOOLEAN MODE)";
        }

        if (isset($paramsToSearch['guia']) && $paramsToSearch['guia'] != '') {
            $searchTerm = '*' . $paramsToSearch['guia'] . '*';
            $escapedTerm = $connection->quote($searchTerm);
            $sql .= " AND MATCH(r.codigo_guia) AGAINST($escapedTerm IN BOOLEAN MODE)";
        }

        if (isset($paramsToSearch['created_at']) && $paramsToSearch['created_at'] != '') {
            $escapedDate = $connection->quote($paramsToSearch['created_at']);
            $sql .= " AND DATE(r.created_at) = $escapedDate";
        }

        $sql .= " ORDER BY r.id_radicado DESC";

        $countSql = str_replace("SELECT r.id_radicado", "SELECT COUNT(*) as total", $sql);
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
                ->select('r')
                ->from('App\DocumentManagement\Domain\Entity\Filed', 'r')
                ->where('r.id_radicado IN (:ids)')
                ->orderBy('r.id_radicado', 'DESC')
                ->setParameter('ids', $pageIds);

            $dqlQuery = $queryBuilder->getQuery();
            $paginator = new Paginator($dqlQuery);
        } else {
            $dqlQuery = $this->getEntityManager()
                ->createQueryBuilder()
                ->select('r')
                ->from('App\DocumentManagement\Domain\Entity\Filed', 'r')
                ->where('1=0')
                ->getQuery();

            $paginator = new Paginator($dqlQuery);
        }

        return new ResponsePaginator($paginator, $totalPages);
    }

    function getFiledById(int $id_filed): Filed|null
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f', 'i')
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'f')
            ->where('f.id_radicado = :id')
            ->setParameter('id', $id_filed)
            ->leftJoin('f.identification', 'i')
            ->getQuery()
            ->getOneOrNullResult();
    }

    function getReportByHourAndDate(): array
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select([
                "DATE_FORMAT(r.created_at, '%W') AS diaSemana",
                "HOUR(r.created_at) AS hora",
                "COUNT(r.id_radicado) AS totalRadicados"
            ])
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'r')
            ->where('r.created_at is not null')
            ->groupBy('diaSemana, hora')
            ->addOrderBy('hora', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    function getReport(\DateTime $dateStart, \DateTime|null $dateEnd): array
    {
        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'r');

        if ($dateEnd == null) {
            $date = $dateStart->format('Y-m-d');
            $query = $query->select([
                "HOUR(r.created_at) AS hora",
                "COUNT(r.id_radicado) AS total"
            ])
            ->where("r.created_at >= '".$date." 00:00:00' and r.created_at <= '".$date." 23:59:59'")
            ->groupBy('hora')
            ->addOrderBy('hora', 'ASC');
        }
        else {
            $initDate = $dateStart->format('Y-m-d');
            $endDate = $dateEnd->format('Y-m-d');
            $query = $query->select([
                "DATE(r.created_at) AS fecha",
                "COUNT(r.id_radicado) AS total"
            ])
                ->where("r.created_at >= '".$initDate." 00:00:00' and r.created_at <= '".$endDate." 23:59:59'")
                ->groupBy('fecha')
                ->addOrderBy('fecha', 'ASC');
        }

        return $query
            ->getQuery()
            ->getArrayResult();
    }

    function getDocumentsByGuidesNumber(array $guides_number) : array
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f', 'i', 'd')
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'f')
            ->where('f.codigo_guia IN (:guides_number)')
            ->setParameter('guides_number', $guides_number)
            ->leftJoin('f.identification', 'i')
            ->leftJoin('f.documents', 'd')
            ->getQuery()
            ->getArrayResult();
    }

    function getDocumentsWithoutRoute(int $days = 0) : array
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f', 'd')
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'f')
            ->join('f.documents', 'd')
            ->where('d.created_at >= :date_start')
            ->andWhere('d.ruta IS NULL')
            ->setParameter('date_start', (new \DateTime('today'))->modify('-' . $days . ' days'))
            ->getQuery()
            ->getArrayResult();
    }

    function getDocumentsByDateAndInPlane(string $time_start, string $time_end, string $difference_days) : array
    {
        $date = new DateTime();
        $queryDateStart = $date->format('Y-m-d') . ' ' . $time_start;
        if ($difference_days > 0) {
            $date->modify('-' . $difference_days . ' days');
            $queryDateStart = $date->format('Y-m-d') . ' ' . $time_start;
        }

        $dateEnd = new DateTime();
        $queryDateEnd = $dateEnd->format('Y-m-d') . ' ' . $time_end;

        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f', 'i', 'd')
            ->from('App\DocumentManagement\Domain\Entity\Filed', 'f')
            ->where('f.created_at <= :date_end')
            ->andWhere('(f.created_at >= :date_start OR d.in_plane = false)')
            // Un radicado sale en el plano una sola vez y completo: nunca si alguno de sus documentos ya fue reportado...
            ->andWhere('NOT EXISTS (SELECT 1 FROM App\DocumentManagement\Domain\Entity\Document dr WHERE dr.fk_radicado = f.id_radicado AND dr.in_plane = true)')
            // ...ni mientras alguno de sus documentos siga sin descargar.
            ->andWhere("NOT EXISTS (SELECT 1 FROM App\DocumentManagement\Domain\Entity\Document dn WHERE dn.fk_radicado = f.id_radicado AND (dn.ruta IS NULL OR dn.ruta = ''))")
            ->setParameter('date_start', $queryDateStart)
            ->setParameter('date_end', $queryDateEnd)
            ->leftJoin('f.identification', 'i')
            ->leftJoin('f.documents', 'd')
            ->getQuery()
            ->getArrayResult();
    }

    function updateInPlane(int $id_documento): void
    {
        $this->getEntityManager()
            ->createQueryBuilder()
            ->update('App\DocumentManagement\Domain\Entity\Document', 'd')
            ->set('d.in_plane', ':in_plane')
            ->where('d.id_documento = :id_documento')
            ->setParameter('id_documento', $id_documento)
            ->setParameter('in_plane', true)
            ->getQuery()
            ->execute();
    }
}