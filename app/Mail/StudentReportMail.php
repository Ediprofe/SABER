<?php

namespace App\Mail;

use App\Models\Enrollment;
use App\Models\Exam;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Enrollment $enrollment,
        public Exam $exam,
        public string $pdfPath,
        public ?int $globalScore = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reporte Individual - {$this->exam->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-report',
            with: [
                'studentName' => $this->enrollment->student->first_name,
                'fullName' => $this->enrollment->student->full_name,
                'examName' => $this->exam->name,
                'examDate' => $this->exam->date?->format('d/m/Y') ?? 'N/A',
                'group' => $this->enrollment->group,
                'globalScore' => $this->globalScore,
            ],
        );
    }

    public function attachments(): array
    {
        $studentName = strtoupper(
            iconv('UTF-8', 'ASCII//TRANSLIT',
                str_replace(' ', '_', $this->enrollment->student->last_name . '_' . $this->enrollment->student->first_name)
            )
        );
        $studentName = preg_replace('/[^A-Z0-9_]/', '', $studentName);

        return [
            Attachment::fromPath($this->pdfPath)
                ->as("Reporte_{$studentName}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
