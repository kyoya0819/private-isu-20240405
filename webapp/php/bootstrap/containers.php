<?php


use DI\Container;
use Libs\Db;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;

$container = new Container();
$container->set("db", function ($c) {
    return new Db();
});

$container->set("view", function ($c) {
    return new class(realpath(dirname(__FILE__) . "/../views/")) extends \Slim\Views\PhpRenderer {
        public function render(\Psr\Http\Message\ResponseInterface $response, string $template, array $data = []): ResponseInterface {
            $data += ["view" => $template];
            return parent::render($response, "layout.php", $data);
        }
    };
});

$container->set("flash", function () {
    return new \Slim\Flash\Messages;
});

$container->set("helper", function ($c) {
    return new class($c) {
        public Db $db;

        public function __construct($c)
        {
            $this->db = $c->get("db");
        }

        public function try_login($account_name, $password)
        {
            $user = $this->db->fetchFirst("SELECT id, account_name, passhash FROM users WHERE account_name = ? AND del_flg = 0", $account_name);
            if ($user !== false && calculate_passhash($user["account_name"], $password) == $user["passhash"]) {
                return $user;
            } elseif ($user) {
                return null;
            } else {
                return null;
            }
        }

        public function get_session_user()
        {
            if (isset($_SESSION["user"], $_SESSION["user"]["id"])) {
                return $this->db->fetchFirst("SELECT id, account_name, authority FROM `users` WHERE `id` = ?", $_SESSION["user"]["id"]);
            } else {
                return null;
            }
        }

        public function make_posts(array $results, $options = []): array
        {
            $options += ["all_comments" => false];
            $all_comments = $options["all_comments"];

            $posts_id = array_map(fn ($v) => $v["id"], $results);
            $posts_placeholder = implode(",", array_pad([], count($posts_id), "?"));

            $comments_count = (function () use ($posts_id, $posts_placeholder) {
                $q = $this->db->prepare("SELECT post_id, COUNT(*) AS post_count from comments WHERE post_id IN($posts_placeholder) GROUP BY post_id");
                $q->execute($posts_id);
                $comments_count = $q->fetchAll(PDO::FETCH_ASSOC);
                return array_combine(array_map(fn ($v) => $v["post_id"], $comments_count), $comments_count)
                    + array_combine($posts_id, array_pad([], count($posts_id), ["post_count" => 0]));
            })();

            $comments = (function () use ($posts_id, $all_comments, $posts_placeholder) {
                $sql = <<<EOF
SELECT
    comments_with_index.id,
    post_id,
    user_id,
    comment,
    comments_with_index.created_at
FROM (
    SELECT 
        *,
        ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY created_at DESC) as comment_index
    FROM comments
) AS comments_with_index 
    LEFT JOIN users 
        ON comments_with_index.user_id = users.id
WHERE
    post_id IN($posts_placeholder) 
  AND
    users.del_flg = 0
EOF;
                $limit = !$all_comments ? " AND comment_index <= 3" : "";
                $q = $this->db->prepare($sql . $limit);
                $q->execute($posts_id);
                $comments = $q->fetchAll(PDO::FETCH_ASSOC);
                $res = [];
                foreach ($comments as $comment) {
                    $res[$comment["post_id"]][] = $comment;
                }
                return $res;
            })();

            $users_id = array_values(array_unique(
                array_merge(
                    array_map(fn ($v) => $v["user_id"], $results),
                    array_map(fn ($v) => $v["user_id"], array_reduce($comments, "array_merge", []))
                )
            ));
            $users_placeholder = implode(",", array_pad([], count($users_id), "?"));

            $users = (function () use ($users_id, $users_placeholder) {
                $q = $this->db->prepare("SELECT id, account_name from users WHERE id IN($users_placeholder)");
                $q->execute($users_id);
                $users = $q->fetchAll(PDO::FETCH_ASSOC);
                return array_combine(array_map(fn ($v) => $v["id"], $users), $users);
            })();


            $posts = [];
            foreach ($results as $post) {
                $post["comment_count"] = $comments_count[$post["id"]]["post_count"];
                $post["comments"] = $comments[$post["id"]] ?? [];
                $post["user"] = $users[$post["user_id"]];
                foreach ($post["comments"] as $key => $comment) {
                    $post["comments"][$key]["user"] = $users[$comment["user_id"]];
                }
                $posts[] = $post;
            }
            return $posts;
        }

    };
});
AppFactory::setContainer($container);
