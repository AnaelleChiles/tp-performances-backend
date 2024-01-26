## Question 2 : Utilisation Server Timing API

**Temps de chargement initial de la page** : TEMPS

**Choix des méthodes à analyser** :

- `getCheapestRoom` 14.53s
- `getReviews` 8.38s
- `getMetas` 4.16s

## Question 3 : Réduction du nombre de connexions PDO

**Temps de chargement de la page** : TEMPS

**Temps consommé par `getDB()`** 

- **Avant** 1.30s

- **Après** 1.34s

## Question 4 : Délégation des opérations de filtrage à la base de données

**Temps de chargement globaux** 

- **Avant** 29.34s

- **Après** 18.34s

#### Amélioration de la méthode `getmetas` et donc de la méthode `getmeta` :

- **Avant** 4.31s

```sql
 SELECT * FROM wp_usermeta;
```

- **Après** 303.60ms

```sql
"SELECT meta_value, meta_key FROM wp_usermeta WHERE user_id=?"
```



#### Amélioration de la méthode `getReviews` :

- **Avant** 8.36s

```sql
 SELECT * FROM wp_posts, wp_postmeta WHERE wp_posts.post_author = :hotelId AND wp_posts.ID = wp_postmeta.post_id AND meta_key = 'rating' AND post_type = 'review'
```

- **Après** 6.36s

```sql
SELECT COUNT(meta_value) AS Nombre, SUM(meta_value) AS Tot FROM wp_posts INNER JOIN wp_postmeta ON wp_posts.ID = wp_postmeta.post_id WHERE wp_posts.post_author = :hotelId AND meta_key = 'rating' AND post_type = 'review';
```



#### Amélioration de la méthode `getCheapestRoom` :

- **Avant** 16.50s

```sql
 SELECT * FROM wp_posts WHERE post_author = :hotelId AND post_type = 'room';
```

- **Après** 11.34s

```sql
SELECT Prix.meta_value AS price, 
Surface.meta_value AS surface, 
Bedroom.meta_value AS bedroom, 
Bathroom.meta_value AS bathroom, 
Types.meta_value AS types, 
Prix.post_id AS id, 
post.post_title AS title, 
Images.meta_value AS images 
FROM 
wp_posts AS post 
INNER JOIN wp_postmeta AS Prix ON Prix.post_id = post.ID AND Prix.meta_key = 'price' 
INNER JOIN wp_postmeta AS Surface ON Surface.post_id= post.ID AND Surface.meta_key = 'surface' 
INNER JOIN wp_postmeta AS Bedroom ON Bedroom.post_id= post.ID AND Bedroom.meta_key = 'bedrooms_count' 
INNER JOIN wp_postmeta AS Bathroom ON Bathroom.post_id= post.ID AND Bathroom.meta_key = 'bathrooms_count' 
INNER JOIN wp_postmeta AS Types ON Types.post_id= post.ID AND Types.meta_key = 'type' 
INNER JOIN wp_postmeta AS Images ON Images.post_id= post.ID AND Images.meta_key = 'coverImage' 
WHERE post_author = :hotelId AND post_type = 'room';
```

## Question 5 : Réduction du nombre de requêtes SQL pour `getMeta`

|                              | **Avant** | **Après** |
|------------------------------|-----------|-----------|
| Nombre d'appels de `getDB()` |   2 201   |    601    |
| Temps de `getMetas`          | TEMPS     | 321.90ms  |

## Question 6 : Création d'un service basé sur une seule requête SQL

**Ne pas faire**

## Question 7 : ajout d'indexes SQL

**Indexes ajoutés**

- `wp_posts` : `post_type, post_author`
- `wp_usermeta` : `user_id, meta_key `
- `wp_postmeta` : `post_id, meta_key`

**Requête SQL d'ajout des indexes** 

```sql
CREATE INDEX Index_posts_post_type ON wp_posts (post_type);
CREATE INDEX Index_posts_post_author ON wp_posts (post_author);

CREATE INDEX Index_usermeta_user_id ON wp_usermeta (user_id);
CREATE INDEX Index_usermeta_meta_key ON wp_usermeta (meta_key);

CREATE INDEX Index_postmeta_post_id ON wp_postmeta (post_id);
CREATE INDEX Index_postmeta_meta_key ON wp_postmeta (meta_key);
```

| Temps de chargement de la page | Sans filtre | Avec filtres |
|--------------------------------|-------------|--------------|
| `UnoptimizedService`           | 645,97 ms   | 607,79 ms    |
| `OneRequestService`            | 294,08 ms   | 240,58 ms    |
[Filtres à utiliser pour mesurer le temps de chargement](http://localhost/?types%5B%5D=Maison&types%5B%5D=Appartement&price%5Bmin%5D=200&price%5Bmax%5D=230&surface%5Bmin%5D=130&surface%5Bmax%5D=150&rooms=5&bathRooms=5&lat=46.988708&lng=3.160778&search=Nevers&distance=30)


