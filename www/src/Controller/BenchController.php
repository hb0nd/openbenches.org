<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

use App\Service\BenchFunctions;
use App\Service\SearchFunctions;

class BenchController extends AbstractController
{
	#[Route('/bench/random/', name: 'random_bench')]
	public function random_bench(): Response {
		$benchFunctions = new BenchFunctions();
		$benchID = $benchFunctions->getRandomBenchID();
		return $this->redirect("/bench/{$benchID}");
	}

	#[Route('/bench/{bench_id}', name: 'show_bench')]
	public function show_bench($bench_id): Response {

		if (!is_numeric( $bench_id )) {
			//	Generate an HTTP 404 response
			throw $this->createNotFoundException( "The bench ID must be an integer." );
        }
		$benchId = (int) $bench_id;

		$benchFunctions = new BenchFunctions();
		$bench = $benchFunctions->getBench( $bench_id );

		if ( isset($bench["bench_id"]) ) {
			return $this->render("bench.html.twig", [
				"bench_id"    => $bench["bench_id"],
				"inscription" => $bench["inscription"],
				"longitude"   => $bench["longitude"],
				"latitude"    => $bench["latitude"],
				"addresses"   => $bench["addresses"],
				"medias"      => $bench["medias"],
				"tags"        => $bench["tags"],
			]);
		}

		//	Has it been merged?
		$searchFunctions = new SearchFunctions();
		$mergedBenchID = $searchFunctions->getMergedBench( $bench_id );

		if ( null != $mergedBenchID ) {
			return $this->redirect( "/bench/{$mergedBenchID}" );
		}
		else {
			//	Generate an HTTP 404 response
			throw $this->createNotFoundException( "The bench does not exist." );
		}
	}
}