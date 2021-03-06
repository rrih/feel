<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * Users Controller
 *
 * @property \App\Model\Table\UsersTable $Users
 * @method \App\Model\Entity\User[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class UsersController extends AppController
{
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $users = $this->Users;
        $this->set('users', $this->paginate($users));
    }

    /**
     * View method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id)
    {
        $user = $this->Users->get($id);
        $this->set(compact('user'));
    }

    /**
     * ユーザー登録
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function signup()
    {
        $user = $this->Users->newEmptyEntity();
        if ($this->request->is('post')) {
            $user = $this->Users->patchEntity($user, $this->request->getData());
            if ($this->Users->save($user)) {
                return $this->redirect(['action' => 'edit']);
            }
            $this->Flash->error(__('エラーが発生しました。再度お試しください。'));
        }
        $this->set('user', $user);
    }

    /**
     * Edit method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit()
    {
        $userId = $this->Authentication->getResult()->getData()->id;
        $user = $this->Users->get($userId);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $user = $this->Users->patchEntity($user, $this->request->getData());
            if ($this->Users->save($user)) {
                return $this->redirect(['action' => 'edit']);
            }
            $this->Flash->error(__('編集できませんでした'));
        }
        $this->set(compact('user'));
    }

    public function like()
    {
        $this->loadModel('Likes');
        $this->loadModel('Posts');
        $userId = $this->Authentication->getResult()->getData()->id;
        // TODO 任意のユーザが like したものを全て引っ張ってくる
        $likes = $this->Likes->find()
            ->select([
                'post_id',
            ])
            ->where([
                'user_id' => $userId // 特定のユーザが like したもののみ、Like モデルを持ってくる
            ])
            ->toList();
        $likes = array_unique($likes);
        $results = [];
        foreach ($likes as $like) {
            // $this->Posts->get($like->post_id) が無い場合を考慮
            // レコードから post を物理削除した時、紐づいたいいね情報が残っているからここでエラーが起きる
            if ($this->Posts->find()->select(['id'])->where(['id' => $like->post_id])->first()) {
                $results[] = $this->Posts->find()->select(['id', 'user_id', 'text', 'like_count', 'created', 'modified'])->where(['id' => $like->post_id])->first();
            }
        }
        $this->set(compact('results'));
    }

    // user の削除は論理削除で行う。 物理削除は admin 用の画面からのみ行えるようにする
    /**
     * Delete method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null|void Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    // public function delete($id = null)
    // {
    //     $this->request->allowMethod(['post', 'delete']);
    //     $user = $this->Users->get($id);
    //     if ($this->Users->delete($user)) {
    //         $this->Flash->success(__('The user has been deleted.'));
    //     } else {
    //         $this->Flash->error(__('The user could not be deleted. Please, try again.'));
    //     }

    //     return $this->redirect(['action' => 'index']);
    // }

    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        // ログインアクションを認証を必要としないように設定することで、
        // 無限リダイレクトループの問題を防ぐことができます
        $this->Authentication->addUnauthenticatedActions(['login', 'signup']);
    }

    public function profile($id = null)
    {
        $isId = $id ? true : false;
        $currentId = $this->Authentication->getResult()->getData()->id;
        if ((int)$id === $currentId) {
            return $this->redirect('/users/profile');
        }
        $currentUser = $this->Users->get($currentId);
        $this->set(compact('currentUser', 'isId'));
        if ($id === null) {
            return;
        }
        $user = $this->Users->get($id);
        $this->set(compact('user'));
    }

    public function login()
    {
        $this->request->allowMethod(['get', 'post']);
        $result = $this->Authentication->getResult();
        // POSTやGETに関係なく、ユーザーがログインしていればリダイレクトします
        if ($result->isValid()) {
            // ログイン成功後に /article にリダイレクトします
            // $redirect = $this->request->getQuery('redirect', [
            //     'controller' => 'Posts',
            //     'action' => 'index',
            // ]);
            return $this->redirect('/posts');

            return $this->redirect($redirect);
        }
        // ユーザーの送信と認証に失敗した場合にエラーを表示します
        if ($this->request->is('post') && !$result->isValid()) {
            $this->Flash->error(__('ログインに失敗しました'));
        }
    }

    public function logout()
    {
        $result = $this->Authentication->getResult();
        // POSTやGETに関係なく、ユーザーがログインしていればリダイレクトします
        if ($result->isValid()) {
            $this->Authentication->logout();
            return $this->redirect(['controller' => 'Posts', 'action' => 'index']);
        }
    }

    /**
     * 引数、$userId はフォローする相手のユーザーID。フォローメソッド。
     *
     * @param [type] $userId
     * @return void
     */
    public function follow($userId)
    {
        $this->autoRender = false;
        $this->loadModel('Relationships');
        $currentId = $this->Authentication->getResult()->getData()->id;
        // 異なるユーザであるかの確認
        // TODO 既にフォローしていないか？の確認もする
        if ((int)$currentId !== (int)$userId) {
            $relationship = $this->Relationships->newEmptyEntity();
            $relationship->follower_id = $currentId;
            $relationship->following_id = $userId;
            if ($this->Relationships->save($relationship)) {
                // フォローできた場合
                return $this->redirect('/users/profile/' . $userId);
            } else {
                // TODO フォローできなかった場合、フラッシュメッセージでも挿入する
            }
        }
    }

    public function unfollow($userId)
    {
        $this->autoRender = false;
        $this->loadModel('Relationships');
        $currentId = $this->Authentication->getResult()->getData()->id;
        // 自分で自分を unfollow などできないはずだけど
        if ((int)$currentId !== (int)$userId) {
            if ($this->Relationships->deleteAll(['follower_id' => $currentId, 'following_id' => $userId])) {
                return $this->redirect('/users/profile/' . $userId);
            }
            // TODO アンフォローできなかった場合の処理
        }
    }

    /**
     * 自分がフォローしているアカウント一覧の表示
     *
     * @return void
     */
    public function following($id = null)
    {
        $this->loadModel('Relationships');
        $followings = [];
        // 自分以外の誰かのフォロー一覧を見たい場合
        if ($id !== null) {
            $followings = $this->Relationships->find()
                ->select([
                    'following_id',
                ])
                ->where([
                    'follower_id' => $id,
                ])
                ->toList();
        } else {
        // 自分のフォロー一覧を見たい場合
            $currentId = $this->Authentication->getResult()->getData()->id;
            $followings = $this->Relationships->find()
                ->select([
                    'following_id',
                ])
                ->where([
                    'follower_id' => $currentId,
                ])
                ->toList();
        }
        $this->set(compact('followings'));
    }

    /**
     * 自分のことをフォローしているアカウント一覧の表示
     *
     * @return void
     */
    public function follower($id = null)
    {
        $this->loadModel('Relationships');
        $followers = [];
        if ($id !== null) {
            $followers = $this->Relationships->find()
                ->select([
                    'follower_id',
                ])
                ->where([
                    'following_id' => $id,
                ])
                ->toList();
        } else {
            $currentId = $this->Authentication->getResult()->getData()->id;
            $followers = $this->Relationships->find()
                ->select([
                    'follower_id',
                ])
                ->where([
                    'following_id' => $currentId,
                ])
                ->toList();
        }
        $this->set(compact('followers'));
    }
}
