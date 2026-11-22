<?php

namespace AiObservability\Feedback;

use AiObservability\User\UserIdResolverInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;

/**
 * Records how satisfied the end user is with a conversation, e.g. from a thumbs up or down under the answer.
 *
 * The score goes out as a span of its own, on the same OTLP export as the traces, with the attributes of the GenAI
 * evaluation event (gen_ai.evaluation.*). The hub joins it to the conversation by gen_ai.conversation.id.
 */
final readonly class Feedback
{
    public const USER_FEEDBACK = 'user_feedback';

    public function __construct(
        private TracerInterface $tracer,
        private bool $captureContent = false,
        private ?UserIdResolverInterface $userIdResolver = null,
    ) {
    }

    /**
     * @param float       $score   from 0 (unsatisfied) to 1 (satisfied): a thumbs down is 0, a thumbs up 1, 4 stars out of 5 is 0.75
     * @param string|null $comment free text of the user, recorded only when capture_content is on
     * @param string|null $label   short category, e.g. "wrong_answer" or "too_long"
     */
    public function record(string $conversationId, float $score, ?string $comment = null, ?string $label = null, string $name = self::USER_FEEDBACK): void
    {
        if ('' === trim($conversationId)) {
            throw new \InvalidArgumentException('The conversation id must not be empty.');
        }

        if ('' === trim($name)) {
            throw new \InvalidArgumentException('The evaluation name must not be empty.');
        }

        if (!is_finite($score) || $score < 0 || $score > 1) {
            throw new \InvalidArgumentException(\sprintf('The feedback score must be between 0 and 1, %s given.', is_nan($score) ? 'NAN' : (string) $score));
        }

        $builder = $this->tracer->spanBuilder('evaluation '.$name)
            ->setParent(false)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('gen_ai.operation.name', 'evaluation')
            ->setAttribute('gen_ai.evaluation.name', $name)
            ->setAttribute('gen_ai.evaluation.score.value', $score)
            ->setAttribute('gen_ai.conversation.id', $conversationId);

        if (null !== $label) {
            $builder->setAttribute('gen_ai.evaluation.score.label', $label);
        }

        if ($this->captureContent && null !== $comment && '' !== $comment) {
            $builder->setAttribute('gen_ai.evaluation.explanation', $comment);
        }

        if (null !== $userId = $this->userIdResolver?->resolve()) {
            $builder->setAttribute('user.id', $userId);
        }

        $builder->startSpan()->end();
    }
}
