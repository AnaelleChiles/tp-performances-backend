<?php

namespace App\Services\Hotel;
use App\Common\Database;
use App\Common\FilterException;
use App\Common\SingletonTrait;
use App\Entities\HotelEntity;
use App\Entities\RoomEntity;
use App\Services\Room\RoomService;
use App\Common\Timers;
use Exception;
use PDO;

/**
 * Une classe utilitaire pour récupérer les données des magasins stockés en base de données
 */
class UnoptimizedHotelService extends AbstractHotelService
{
  use SingletonTrait;

  protected function __construct()
  {
    parent::__construct(new RoomService());
  }


  /**
   * Récupère une nouvelle instance de connexion à la base de donnée
   *
   * @return PDO
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getDB(): PDO
  {
    $timer = Timers::getInstance();
    $id = $timer->startTimer('getDB');
    $pdo = Database::getInstance();
    $timer->endTimer("getDB", $id);
    return $pdo->getPDO();
  }

  /**
   * Récupère toutes les meta données de l'instance donnée
   *
   * @param HotelEntity $hotel
   *
   * @return array
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getMetas(HotelEntity $hotel): array
  {
    $timer = Timers::getInstance();
    $idhotel = $hotel->getId();
    $id = $timer->startTimer('getMetas');

    $db = $this->getDB();
    $stmt = $db->prepare("SELECT meta_value, meta_key FROM wp_usermeta WHERE user_id=?");
    $stmt->execute(array($idhotel));

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $value=array();

    foreach ($result as $row) {
      $value[$row['meta_key']] = $row['meta_value'];
    }
    
    $metaDatas = [
      'address' => [
        'address_1' => $value['address_1'],
        'address_2' => $value['address_2'],
        'address_city' => $value['address_city'],
        'address_zip' =>$value['address_zip'],
        'address_country' => $value['address_country'],
      ],
      'geo_lat' => $value['geo_lat'],
      'geo_lng' =>$value['geo_lng'],
      'coverImage' => $value['coverImage'],
      'phone' => $value['phone'],
    ];
    $timer->endTimer("getMetas", $id);
    return $metaDatas;
  }

  /**
   * Récupère les données liées aux évaluations des hotels (nombre d'avis et moyenne des avis)
   *
   * @param HotelEntity $hotel
   *
   * @return array{rating: int, count: int}
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getReviews(HotelEntity $hotel): array
  {
    $timer = Timers::getInstance();
    $id = $timer->startTimer('getReviews');
    // Récupère tous les avis d'un hotel
    $stmt = $this->getDB()->prepare("SELECT COUNT(meta_value) AS Nombre, ROUND(AVG(meta_value),0) AS Tot FROM wp_posts INNER JOIN wp_postmeta ON wp_posts.ID = wp_postmeta.post_id WHERE wp_posts.post_author = :hotelId AND meta_key = 'rating' AND post_type = 'review';");
    $stmt->execute(['hotelId' => $hotel->getId()]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reviews as $row) {
      $output = [
        'rating' => $row['Tot'],
        'count' => $row['Nombre'],
      ];

    }
    $timer->endTimer("getReviews", $id);
    return $output;
  }


  /**
   * Récupère les données liées à la chambre la moins chère des hotels
   *
   * @param HotelEntity $hotel
   * @param array{
   *   search: string | null,
   *   lat: string | null,
   *   lng: string | null,
   *   price: array{min:float | null, max: float | null},
   *   surface: array{min:int | null, max: int | null},
   *   rooms: int | null,
   *   bathRooms: int | null,
   *   types: string[]
   * }                  $args Une liste de paramètres pour filtrer les résultats
   *
   * @throws FilterException
   * @return RoomEntity
   */
  protected function getCheapestRoom(HotelEntity $hotel, array $args = []): RoomEntity
  {
    $timer = Timers::getInstance();
    $id = $timer->startTimer('getCheapestRoom');
    // On charge toutes les chambres de l'hôtel
    $stmt = $this->getDB()->prepare("SELECT 
    Prix.meta_value AS price,
    Surface.meta_value AS surface,
    Bedroom.meta_value AS bedroom,
    Bathroom.meta_value AS bathroom,
    Types.meta_value AS types,
    Prix.post_id AS id,
    post.post_title AS title,
    Images.meta_value AS images

FROM 
    wp_posts AS post
    
    INNER JOIN wp_postmeta AS Prix 
        ON Prix.post_id = post.ID AND Prix.meta_key = 'price'   

    INNER JOIN wp_postmeta AS Surface
        ON Surface.post_id= post.ID AND Surface.meta_key = 'surface'
       
    INNER JOIN wp_postmeta AS Bedroom
        ON Bedroom.post_id= post.ID AND Bedroom.meta_key = 'bedrooms_count'
        
    INNER JOIN wp_postmeta AS Bathroom
        ON Bathroom.post_id= post.ID AND Bathroom.meta_key = 'bathrooms_count'
        
    INNER JOIN wp_postmeta AS Types
        ON Types.post_id= post.ID AND Types.meta_key = 'type'
        
    INNER JOIN wp_postmeta AS Images
        ON Images.post_id= post.ID AND Images.meta_key = 'coverImage'
        
       WHERE post_author = :hotelId AND post_type = 'room';");
    $stmt->execute(['hotelId' => $hotel->getId()]);
    $resultat = $stmt->fetchAll(PDO::FETCH_ASSOC);
    /**
     * On convertit les lignes en instances de chambres (au passage ça charge toutes les données).
     *
     * @var RoomEntity[] $rooms ;
     */
    $rooms = array();
    foreach ($resultat as $row) {
      $chambre = new RoomEntity();
      $chambre->setBathRoomsCount($row['bathroom']);
      $chambre->setBedRoomsCount($row['bedroom']);
      $chambre->setPrice($row['price']);
      $chambre->setSurface($row['surface']);
      $chambre->setType($row['types']);
      $chambre->setCoverImageUrl($row['images']);
      $chambre->setId($row['id']);
      $chambre->setTitle($row['title']);
      $rooms[]=$chambre;
    }

    // On exclut les chambres qui ne correspondent pas aux critères
    $filteredRooms = [];

    foreach ($rooms as $room) {
      if (isset($args['surface']['min']) && $room->getSurface() < $args['surface']['min'])
        continue;

      if (isset($args['surface']['max']) && $room->getSurface() > $args['surface']['max'])
        continue;

      if (isset($args['price']['min']) && intval($room->getPrice()) < $args['price']['min'])
        continue;

      if (isset($args['price']['max']) && intval($room->getPrice()) > $args['price']['max'])
        continue;

      if (isset($args['rooms']) && $room->getBedRoomsCount() < $args['rooms'])
        continue;

      if (isset($args['bathRooms']) && $room->getBathRoomsCount() < $args['bathRooms'])
        continue;

      if (isset($args['types']) && !empty($args['types']) && !in_array($room->getType(), $args['types']))
        continue;

      $filteredRooms[] = $room;
    }

    // Si aucune chambre ne correspond aux critères, alors on déclenche une exception pour retirer l'hôtel des résultats finaux de la méthode list().
    if (count($filteredRooms) < 1)
      throw new FilterException("Aucune chambre ne correspond aux critères");


    // Trouve le prix le plus bas dans les résultats de recherche
    $cheapestRoom = null;
    foreach ($filteredRooms as $room):
      if (!isset($cheapestRoom)) {
        $cheapestRoom = $room;
        continue;
      }

      if (intval($room->getPrice()) < intval($cheapestRoom->getPrice()))
        $cheapestRoom = $room;
    endforeach;

    $timer->endTimer("getCheapestRoom", $id);
    return $cheapestRoom;
  }


  /**
   * Calcule la distance entre deux coordonnées GPS
   *
   * @param $latitudeFrom
   * @param $longitudeFrom
   * @param $latitudeTo
   * @param $longitudeTo
   *
   * @return float|int
   */
  protected function computeDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo): float|int
  {
    return (111.111 * rad2deg(acos(min(1.0, cos(deg2rad($latitudeTo))
      * cos(deg2rad($latitudeFrom))
      * cos(deg2rad($longitudeTo - $longitudeFrom))
      + sin(deg2rad($latitudeTo))
      * sin(deg2rad($latitudeFrom))))));
  }


  /**
   * Construit une ShopEntity depuis un tableau associatif de données
   *
   * @throws Exception
   */
  protected function convertEntityFromArray(array $data, array $args): HotelEntity
  {

    $hotel = (new HotelEntity())
      ->setId($data['ID'])
      ->setName($data['display_name']);
  
    // Charge les données meta de l'hôtel
    $metasData = $this->getMetas($hotel);
    $hotel->setAddress($metasData['address']);
    $hotel->setGeoLat($metasData['geo_lat']);
    $hotel->setGeoLng($metasData['geo_lng']);
    $hotel->setImageUrl($metasData['coverImage']);
    $hotel->setPhone($metasData['phone']);

    // Définit la note moyenne et le nombre d'avis de l'hôtel
    $reviewsData = $this->getReviews($hotel);
    $hotel->setRating($reviewsData['rating']);
    $hotel->setRatingCount($reviewsData['count']);

    // Charge la chambre la moins chère de l'hôtel
    $cheapestRoom = $this->getCheapestRoom($hotel, $args);
    $hotel->setCheapestRoom($cheapestRoom);

    // Verification de la distance
    if (isset($args['lat']) && isset($args['lng']) && isset($args['distance'])) {
      $hotel->setDistance($this->computeDistance(
        floatval($args['lat']),
        floatval($args['lng']),
        floatval($hotel->getGeoLat()),
        floatval($hotel->getGeoLng())
      ));

      if ($hotel->getDistance() > $args['distance'])
        throw new FilterException("L'hôtel est en dehors du rayon de recherche");
    }
    return $hotel;
  }


  /**
   * Retourne une liste de boutiques qui peuvent être filtrées en fonction des paramètres donnés à $args
   *
   * @param array{
   *   search: string | null,
   *   lat: string | null,
   *   lng: string | null,
   *   price: array{min:float | null, max: float | null},
   *   surface: array{min:int | null, max: int | null},
   *   bedrooms: int | null,
   *   bathrooms: int | null,
   *   types: string[]
   * } $args Une liste de paramètres pour filtrer les résultats
   *
   * @throws Exception
   * @return HotelEntity[] La liste des boutiques qui correspondent aux paramètres donnés à args
   */
  public function list(array $args = []): array
  {
    $db = $this->getDB();
    $stmt = $db->prepare("SELECT * FROM wp_users");
    $stmt->execute();

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      try {
        $results[] = $this->convertEntityFromArray($row, $args);
      } catch (FilterException) {
        // Des FilterException peuvent être déclenchées pour exclure certains hotels des résultats
      }
    }
    return $results;
  }
}