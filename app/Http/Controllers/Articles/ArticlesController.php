<?php

namespace App\Http\Controllers\Articles;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Http\Requests\ArticleRequest;
use App\Jobs\CreateArticle;
use App\Jobs\DeleteArticle;
use App\Jobs\UpdateArticle;
use App\Models\Article;
use App\Models\Tag;
use App\Models\User;
use App\Policies\ArticlePolicy;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ArticlesController extends Controller
{
    public function __construct()
    {
        $this->middleware([Authenticate::class, EnsureEmailIsVerified::class], ['except' => ['index', 'show']]);
    }

    public function index(Request $request)
    {
        $pinnedArticles = Article::published()
            ->pinned()
            ->latest('submitted_at')
            ->take(4)
            ->get();
        $moderators = User::moderators()->get();
        $canonical = canonical('articles', $request->only('sortBy', 'tag'));

        return view('articles.index', [
            'pinnedArticles' => $pinnedArticles,
            'moderators' => $moderators,
            'canonical' => $canonical,
        ]);
    }

    public function show(Article $article)
    {
        $user = Auth::user();

        abort_unless(
            $article->isPublished() || ($user && ($article->isAuthoredBy($user) || $user->isAdmin() || $user->isModerator())),
            404,
        );

        $trendingArticles = Cache::remember('trendingArticles', now()->addHour(), function () use ($article) {
            return Article::published()
                ->trending()
                ->whereKeyNot($article->id)
                ->limit(3)
                ->get();
        });

        return view('articles.show', [
            'article' => $article,
            'trendingArticles' => $trendingArticles,
        ]);
    }

    public function create()
    {
        return view('articles.create', [
            'tags' => Tag::all(),
            'selectedTags' => old('tags', []),
        ]);
    }

    public function store(ArticleRequest $request)
    {
        $article = $this->dispatchNow(CreateArticle::fromRequest($request));

        $this->success($request->shouldBeSubmitted() ? 'articles.submitted' : 'articles.created');

        return redirect()->route('articles.show', $article->slug());
    }

    public function edit(Article $article)
    {
        $this->authorize(ArticlePolicy::UPDATE, $article);

        return view('articles.edit', [
            'article' => $article,
            'tags' => Tag::all(),
            'selectedTags' => old('tags', $article->tags()->pluck('id')->toArray()),
        ]);
    }

    public function update(ArticleRequest $request, Article $article)
    {
        $this->authorize(ArticlePolicy::UPDATE, $article);

        $wasNotPreviouslySubmitted = $article->isNotSubmitted();

        $article = $this->dispatchNow(UpdateArticle::fromRequest($article, $request));

        if ($wasNotPreviouslySubmitted && $request->shouldBeSubmitted()) {
            $this->success('articles.submitted');
        } else {
            $this->success('articles.updated');
        }

        return redirect()->route('articles.show', $article->slug());
    }

    public function delete(Article $article)
    {
        $this->authorize(ArticlePolicy::DELETE, $article);

        $this->dispatchNow(new DeleteArticle($article));

        $this->success('articles.deleted');

        return redirect()->route('articles');
    }
}
