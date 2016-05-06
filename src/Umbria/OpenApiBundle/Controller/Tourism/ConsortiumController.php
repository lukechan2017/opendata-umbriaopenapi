<?php

namespace Umbria\OpenApiBundle\Controller\Tourism;

use DateTime;
use Doctrine\ORM\EntityManager;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\View\View;
use JMS\DiExtraBundle\Annotation as DI;
use Knp\Component\Pager\Pagination\AbstractPagination;
use Knp\Component\Pager\Paginator;
use Nelmio\ApiDocBundle\Annotation as ApiDoc;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Serializer;
use Umbria\OpenApiBundle\Entity\Tourism\Setting;
use Umbria\OpenApiBundle\Serializer\View\EntityResponse;
use Umbria\OpenApiBundle\Service\CurlBuilder;
use Umbria\OpenApiBundle\Service\FilterBag;

class ConsortiumController extends FOSRestController
{
    const DEFAULT_PAGE_SIZE = 100;
    const DATASET_TOURISM_CONSORTIUM = 'tourism-consortium';

    /**
     * @var EntityManager
     * @DI\Inject("doctrine.orm.entity_manager")
     */
    public $em;

    /**
     * @var CurlBuilder
     * @DI\Inject("umbria_open_api.curl_builder")
     */
    public $curlBuilder;

    /**
     * @var FilterBag
     * @DI\Inject("umbria_open_api.filter_bag")
     */
    private $filterBag;

    /**
     * @var Paginator
     * @DI\Inject("knp_paginator")
     */
    private $paginator;

    /**
     * @var Serializer
     * @DI\Inject("jms_serializer")
     */
    public $serializer;

    /**
     * @Rest\Options(pattern="/open-api/tourism-consortium")
     *
     * @return Response
     */
    public function optionsTourismConsortiumAction()
    {
        $response = new Response();

        $response->setContent(json_encode(array(
            'Allow' => 'GET,OPTIONS',
            'GET' => array(
                'description' => 'A get request for consortiums',
            ),
        )));
        $response->setStatusCode(Response::HTTP_OK);
        $response->headers->set('Content-Type', 'json');

        return $response;
    }

    /**
     * @Rest\Get(pattern="/open-api/tourism-consortium")
     *
     * @param Request $request
     *
     * @return View
     *
     * @internal param Request $request
     *
     * @ApiDoc\ApiDoc(
     *  section = "Tourism",
     *  description = "Lista consorzi regione Umbria",
     *  tags = {
     *      "beta"
     *  },
     *  parameters={
     *      {"name"="start", "dataType"="integer", "required"=false, "description"="Elemento da cui partire"},
     *      {"name"="limit", "dataType"="integer", "required"=false, "description"="Numero di elementi restituiti"}
     *  },
     *  statusCodes={
     *      200="Returned when successful"
     *  }
     * )
     */
    public function getTourismConsortiumListAction(Request $request)
    {
        $daysToOld = $this->container->getParameter('consortium_days_to_old');
        $url = $this->container->getParameter('url_consortium');
        $urlSilkSameAs = $this->container->getParameter('url_consortium_silk');

        $filters = $this->filterBag->getFilterBag($request);
        $offset = $filters->has('start') ? $filters->get('start') : 0;
        $limit = $filters->has('limit') ? $filters->get('limit') : self::DEFAULT_PAGE_SIZE;
        if ($limit == 0) {
            $limit = self::DEFAULT_PAGE_SIZE;
        }
        $page = floor($offset / $limit) + 1;

        /** @var Setting $setting */
        $setting = $this->em->getRepository('UmbriaOpenApiBundle:Tourism\Setting')->findOneBy(array('datasetName' => self::DATASET_TOURISM_CONSORTIUM));
        if ($setting != null) {
            $diff = $setting->getUpdatedAt()->diff(new DateTime('now'));
            // controllo intervallo di tempo da ultima estrazione
            if ($diff->days >= $daysToOld) {
                $setting->setDatasetName(self::DATASET_TOURISM_CONSORTIUM);
                $setting->setUpdatedAtValue();
                $this->em->persist($setting);
                $this->em->flush();

                $this->curlBuilder->updateEntities($url, self::DATASET_TOURISM_CONSORTIUM, $urlSilkSameAs);
            }
        } else {
            $setting = new Setting();
            $setting->setDatasetName(self::DATASET_TOURISM_CONSORTIUM);
            $setting->setUpdatedAtValue();
            $this->em->persist($setting);
            $this->em->flush();

            $this->curlBuilder->updateEntities($url, self::DATASET_TOURISM_CONSORTIUM, $urlSilkSameAs);
        }

        $builder = $this->em->createQueryBuilder()
            ->select('a')
            ->from('UmbriaOpenApiBundle:Tourism\Consortium', 'a');

        /** @var AbstractPagination $resultsPagination */
        $resultsPagination = $this->paginator->paginate($builder, $page, $limit);
        /** @var AbstractPagination $countPagination */
        $countPagination = $this->paginator->paginate($builder, 1, 1);

        $viewGroups = array(
            'response',
            'rdf.*', 'consortium.*', 'address.*', 'phone.*', 'fax-number.*', 'mail.*', 'homepage.*',
        );

        $view = new View(new EntityResponse($resultsPagination->getItems(), count($resultsPagination), $countPagination->getTotalItemCount()));
        $view->getSerializationContext()->setGroups($viewGroups);

        return $view;
    }
}
