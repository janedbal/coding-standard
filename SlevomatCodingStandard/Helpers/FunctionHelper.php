<?php declare(strict_types = 1);

namespace SlevomatCodingStandard\Helpers;

class FunctionHelper
{

	public static function getName(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): string
	{
		$tokens = $codeSnifferFile->getTokens();
		return $tokens[$codeSnifferFile->findNext(T_STRING, $functionPointer + 1, $tokens[$functionPointer]['parenthesis_opener'])]['content'];
	}

	public static function getFullyQualifiedName(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): string
	{
		$name = self::getName($codeSnifferFile, $functionPointer);
		$namespace = NamespaceHelper::findCurrentNamespaceName($codeSnifferFile, $functionPointer);

		if (self::isMethod($codeSnifferFile, $functionPointer)) {
			foreach (array_reverse($codeSnifferFile->getTokens()[$functionPointer]['conditions'], true) as $conditionPointer => $conditionTokenCode) {
				if ($conditionTokenCode === T_ANON_CLASS) {
					return sprintf('class@anonymous::%s', $name);
				} elseif (in_array($conditionTokenCode, [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
					$name = sprintf('%s%s::%s', NamespaceHelper::NAMESPACE_SEPARATOR, ClassHelper::getName($codeSnifferFile, $conditionPointer), $name);
					break;
				}
			}

			return $namespace !== null ? sprintf('%s%s%s', NamespaceHelper::NAMESPACE_SEPARATOR, $namespace, $name) : $name;
		} else {
			return $namespace !== null ? sprintf('%s%s%s%s', NamespaceHelper::NAMESPACE_SEPARATOR, $namespace, NamespaceHelper::NAMESPACE_SEPARATOR, $name) : $name;
		}
	}

	public static function isAbstract(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): bool
	{
		return !isset($codeSnifferFile->getTokens()[$functionPointer]['scope_opener']);
	}

	public static function isMethod(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): bool
	{
		foreach (array_reverse($codeSnifferFile->getTokens()[$functionPointer]['conditions']) as $conditionTokenCode) {
			if (in_array($conditionTokenCode, [T_CLASS, T_INTERFACE, T_TRAIT, T_ANON_CLASS], true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param \PHP_CodeSniffer_File $codeSnifferFile
	 * @param int $functionPointer
	 * @return string[]
	 */
	public static function getParametersNames(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): array
	{
		$tokens = $codeSnifferFile->getTokens();

		$parametersNames = [];
		for ($i = $tokens[$functionPointer]['parenthesis_opener'] + 1; $i < $tokens[$functionPointer]['parenthesis_closer']; $i++) {
			if ($tokens[$i]['code'] === T_VARIABLE) {
				$parametersNames[] = $tokens[$i]['content'];
			}
		}

		return $parametersNames;
	}

	/**
	 * @param \PHP_CodeSniffer_File $codeSnifferFile
	 * @param int $functionPointer
	 * @return string[]
	 */
	public static function getParametersWithoutTypeHint(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): array
	{
		return array_keys(array_filter(self::getParametersTypeHints($codeSnifferFile, $functionPointer), function (ParameterTypeHint $parameterTypeHint = null): bool {
			return $parameterTypeHint === null;
		}));
	}

	/**
	 * @param \PHP_CodeSniffer_File $codeSnifferFile
	 * @param int $functionPointer
	 * @return \SlevomatCodingStandard\Helpers\ParameterTypeHint[]|null[]
	 */
	public static function getParametersTypeHints(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): array
	{
		$tokens = $codeSnifferFile->getTokens();

		$parametersTypeHints = [];
		for ($i = $tokens[$functionPointer]['parenthesis_opener'] + 1; $i < $tokens[$functionPointer]['parenthesis_closer']; $i++) {
			if ($tokens[$i]['code'] === T_VARIABLE) {
				$parameterName = $tokens[$i]['content'];
				$typeHint = null;
				$isNullable = false;

				$previousToken = $i;
				do {
					$previousToken = TokenHelper::findPreviousExcluding($codeSnifferFile, array_merge(TokenHelper::$ineffectiveTokenCodes, [T_BITWISE_AND, T_ELLIPSIS]), $previousToken - 1, $tokens[$functionPointer]['parenthesis_opener'] + 1);
					if ($previousToken !== null) {
						$isTypeHint = $tokens[$previousToken]['code'] !== T_COMMA && $tokens[$previousToken]['code'] !== T_NULLABLE;
						if ($tokens[$previousToken]['code'] === T_NULLABLE) {
							$isNullable = true;
						}
					} else {
						$isTypeHint = false;
					}
					if ($isTypeHint) {
						$typeHint = $tokens[$previousToken]['content'] . $typeHint;
					}
				} while ($isTypeHint);

				$equalsPointer = TokenHelper::findNextEffective($codeSnifferFile, $i + 1, $tokens[$functionPointer]['parenthesis_closer']);
				$isOptional = $equalsPointer !== null && $tokens[$equalsPointer]['code'] === T_EQUAL;

				$parametersTypeHints[$parameterName] = $typeHint !== null ? new ParameterTypeHint($typeHint, $isNullable, $isOptional) : null;
			}
		}

		return $parametersTypeHints;
	}

	public static function returnsValue(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): bool
	{
		return self::innerReturnsValue($codeSnifferFile, $functionPointer, false);
	}

	public static function returnsVoid(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): bool
	{
		return self::innerReturnsValue($codeSnifferFile, $functionPointer, true);
	}

	private static function innerReturnsValue(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer, bool $requireVoid): bool
	{
		$tokens = $codeSnifferFile->getTokens();

		for ($i = $tokens[$functionPointer]['scope_opener'] + 1; $i < $tokens[$functionPointer]['scope_closer']; $i++) {
			$nextEffectiveTokenCode = $tokens[TokenHelper::findNextEffective($codeSnifferFile, $i + 1)]['code'];
			if ($requireVoid) {
				$checkReturn = $nextEffectiveTokenCode === T_SEMICOLON;
			} else {
				$checkReturn = $nextEffectiveTokenCode !== T_SEMICOLON;
			}
			if ($tokens[$i]['code'] === T_RETURN && $checkReturn) {
				foreach (array_reverse($tokens[$i]['conditions'], true) as $conditionPointer => $conditionTokenCode) {
					if ($conditionTokenCode === T_CLOSURE || $conditionTokenCode === T_ANON_CLASS) {
						continue 2;
					} elseif ($conditionPointer === $functionPointer) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * @param \PHP_CodeSniffer_File $codeSnifferFile
	 * @param int $functionPointer
	 * @return \SlevomatCodingStandard\Helpers\ReturnTypeHint|null
	 */
	public static function findReturnTypeHint(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer)
	{
		$tokens = $codeSnifferFile->getTokens();

		$isAbstract = self::isAbstract($codeSnifferFile, $functionPointer);

		$colonToken = $isAbstract
			? $codeSnifferFile->findNext(T_COLON, $tokens[$functionPointer]['parenthesis_closer'] + 1, null, false, null, true)
			: $codeSnifferFile->findNext(T_COLON, $tokens[$functionPointer]['parenthesis_closer'] + 1, $tokens[$functionPointer]['scope_opener'] - 1);

		if ($colonToken === false) {
			return null;
		}

		$abstractExcludeTokens = array_merge(TokenHelper::$ineffectiveTokenCodes, [T_SEMICOLON]);

		$nullableToken = $isAbstract
			? $codeSnifferFile->findNext($abstractExcludeTokens, $colonToken + 1, null, true, null, true)
			: $codeSnifferFile->findNext(TokenHelper::$ineffectiveTokenCodes, $colonToken + 1, $tokens[$functionPointer]['scope_opener'] - 1, true);

		$nullable = $nullableToken !== false && $tokens[$nullableToken]['code'] === T_NULLABLE;

		$typeHint = null;
		$nextToken = $nullable ? $nullableToken : $colonToken;
		do {
			$nextToken = $isAbstract
				? $codeSnifferFile->findNext($abstractExcludeTokens, $nextToken + 1, null, true, null, true)
				: $codeSnifferFile->findNext(TokenHelper::$ineffectiveTokenCodes, $nextToken + 1, $tokens[$functionPointer]['scope_opener'] - 1, true);

			$isTypeHint = $nextToken !== false;
			if ($isTypeHint) {
				$typeHint .= $tokens[$nextToken]['content'];
			}
		} while ($isTypeHint);

		return $typeHint !== null ? new ReturnTypeHint($typeHint, $nullable) : null;
	}

	public static function hasReturnTypeHint(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): bool
	{
		return self::findReturnTypeHint($codeSnifferFile, $functionPointer) !== null;
	}

	/**
	 * @param \PHP_CodeSniffer_File $codeSnifferFile
	 * @param int $functionPointer
	 * @return \SlevomatCodingStandard\Helpers\Annotation[]
	 */
	public static function getParametersAnnotations(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer): array
	{
		return AnnotationHelper::getAnnotationsByName($codeSnifferFile, $functionPointer, '@param');
	}

	/**
	 * @param \PHP_CodeSniffer_File $codeSnifferFile
	 * @param int $functionPointer
	 * @return \SlevomatCodingStandard\Helpers\Annotation|null
	 */
	public static function findReturnAnnotation(\PHP_CodeSniffer_File $codeSnifferFile, int $functionPointer)
	{
		$returnAnnotations = AnnotationHelper::getAnnotationsByName($codeSnifferFile, $functionPointer, '@return');

		if (count($returnAnnotations) === 0) {
			return null;
		}

		return $returnAnnotations[0];
	}

}
