<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Adventure\Entity\Player;
use App\Content\Entity\Answer;
use App\Content\Entity\Format;
use App\Content\Entity\Question;
use App\Content\Entity\Quiz;
use App\Content\Gateway\ResponseGateway;
use App\Content\Gateway\SessionGateway;
use App\Content\UseCase\StartQuizSession\StartQuizSessionHandler;
use App\Content\UseCase\StartQuizSession\StartQuizSessionInput;
use App\Content\UseCase\SubmitResponse\SubmitResponseHandler;
use App\Content\UseCase\SubmitResponse\SubmitResponseInput;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Validator\Validation;

final class TakeQuizTest extends TestCase
{
    /**
     * @test
     */
    public function shouldCreateViolationIfFormatMismatchWithAnswers(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAnnotationMapping()
            ->getValidator();

        $submitResponseInput = new SubmitResponseInput();

        $answer1 = new Answer();
        self::setAnswerId($answer1, 1);
        $answer2 = new Answer();
        self::setAnswerId($answer2, 2);

        $question = new Question();
        $question->setFormat(Format::Unique);
        $question->getAnswers()->add($answer1);
        $question->getAnswers()->add($answer2);

        $response = new Quiz\Response();
        $response->setQuestion($question);

        $submitResponseInput->response = $response;
        $submitResponseInput->answers = [$answer1, $answer2];

        self::assertCount(1, $validator->validate($submitResponseInput));
    }

    /**
     * @test
     */
    public function shouldCreateViolationIfAnswerIsNotInQuestion(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAnnotationMapping()
            ->getValidator();

        $submitResponseInput = new SubmitResponseInput();

        $answer1 = new Answer();
        self::setAnswerId($answer1, 1);
        $answer2 = new Answer();
        self::setAnswerId($answer2, 2);

        $question = new Question();
        $question->setFormat(Format::Unique);
        $question->getAnswers()->add($answer1);
        $question->getAnswers()->add($answer2);

        $response = new Quiz\Response();
        $response->setQuestion($question);

        $answer3 = new Answer();
        self::setAnswerId($answer3, 3);

        $submitResponseInput->response = $response;
        $submitResponseInput->answers = [$answer3];

        self::assertCount(1, $validator->validate($submitResponseInput));
    }

    /**
     * @test
     */
    public function shouldStartAndFinishQuiz(): void
    {
        $player = new Player();

        $quiz = new Quiz();

        for ($j = 1; $j <= 5; ++$j) {
            $question = new Question();
            $question->setQuiz($quiz);
            $quiz->getQuestions()->add($question);
            for ($i = 1; $i <= 3; ++$i) {
                $answer = new Answer();
                $answer->setGood(0 === $i % 2);
                $question->addAnswer($answer);
                $reflectionProperty = new \ReflectionProperty(Answer::class, 'id');
                $reflectionProperty->setAccessible(true);
                $reflectionProperty->setValue($answer, $i);
            }
        }

        $startQuizSession = new StartQuizSessionInput();
        $startQuizSession->player = $player;
        $startQuizSession->quiz = $quiz;

        $sessionGateway = self::createMock(SessionGateway::class);
        $sessionGateway
            ->expects(self::once())
            ->method('start')
            ->with(self::isInstanceOf(Quiz\Session::class));
        $sessionGateway
            ->expects(self::once())
            ->method('finish')
            ->with(self::isInstanceOf(Quiz\Session::class));

        $responseGateway = self::createMock(ResponseGateway::class);
        $responseGateway
            ->expects(self::exactly(5))
            ->method('submit')
            ->with(self::isInstanceOf(Quiz\Response::class));

        $startQuizHandler = new StartQuizSessionHandler($sessionGateway);

        $session = $startQuizHandler($startQuizSession);

        self::assertEquals($player, $session->getPlayer());
        self::assertEquals($quiz, $session->getQuiz());
        self::assertCount($quiz->getQuestions()->count(), $session->getResponses());
        self::assertNull($session->getFinishedAt());

        $submitResponseHandler = new SubmitResponseHandler($responseGateway, $sessionGateway);

        foreach ($session->getResponses() as $i => $response) {
            $submitResponse = new SubmitResponseInput();
            $submitResponse->response = $response;
            /** @var Answer $answer */
            $answer = $response->getQuestion()->getAnswers()->get(0 === $i % 2 ? 1 : 0);
            $submitResponse->answers = [$answer];
            $submitResponseHandler($submitResponse);
            self::assertEquals(0 === $i % 2, $response->isValid());
        }

        self::assertCount(
            3,
            $session->getResponses()->filter(static fn (Quiz\Response $response) => $response->isValid())
        );
        self::assertTrue(
            $session
                ->getResponses()
                ->forAll(static fn (int $key, Quiz\Response $response): bool => null !== $response->getRespondedAt())
        );
        self::assertNotNull($session->getFinishedAt());
    }

    private static function setAnswerId(Answer $answer, int $int): void
    {
        $reflectionProperty = new ReflectionProperty($answer, 'id');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($answer, $int);
    }
}
